import base64
import binascii
import fcntl
import hashlib
import html
import json
import os
import re
import time
from contextlib import contextmanager
from copy import deepcopy
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote

from anki.collection import AddNoteRequest, Collection
from anki.decks import DEFAULT_DECK_ID
from anki.stdmodels import get_stock_notetypes

MARKER = re.compile(r'<!--bruno:([A-Za-z0-9_-]+)-->')
MNEMONIC_MARKUP = re.compile(MARKER.pattern + r'(?:<div><strong>Eselsbrücke:</strong> .*?</div>)?', re.S)
MEDIA_NAME = re.compile(r'bruno-(\d+)-[0-9a-f]{32}\.(?:pdf|txt|png|jpg|webp|mp3|mp4|svg)')
ANSWER_SEPARATOR = re.compile(r'<hr[^>]+\bid=["\']?answer\b[^>]*>', re.I)
TYPE_ANSWER = re.compile(r'\[\[type:([^\]\r\n]+)\]\]')
AV_MARKER = re.compile(r'\[(?:sound:([^\]]*)|anki:play:[^\]]*)\]')
MEDIA_TYPES = {
    'png': 'image/png', 'jpg': 'image/jpeg', 'jpeg': 'image/jpeg', 'gif': 'image/gif',
    'webp': 'image/webp', 'avif': 'image/avif',
    'mp3': 'audio/mpeg', 'wav': 'audio/wav', 'ogg': 'audio/ogg', 'opus': 'audio/ogg',
    'm4a': 'audio/mp4', 'mp4': 'video/mp4', 'webm': 'video/webm',
}


class Rejected(Exception):
    pass


@contextmanager
def collection(directory):
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (directory / 'collection.lock').open('a') as lock:
        deadline = time.monotonic() + 5
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if time.monotonic() >= deadline:
                    raise Rejected('Anki ist gerade beschäftigt. Bitte gleich erneut versuchen.', 503)
                time.sleep(0.08)

        col = Collection(str(directory / 'collection.anki2'))
        try:
            yield col
        finally:
            if col.db is not None:
                col.close()


def unpack(value):
    start, *remainder = MARKER.split(value, maxsplit=1)
    try:
        meta = json.loads(base64.urlsafe_b64decode(remainder[0] + '===')) if remainder else {}
    except (binascii.Error, ValueError):
        meta = {}
    return start.rstrip(), meta if isinstance(meta, dict) else {}


def pack(text, meta):
    encoded = base64.urlsafe_b64encode(json.dumps(meta, ensure_ascii=False).encode()).decode().rstrip('=')
    return f'{text}<!--bruno:{encoded}-->'


def metadata(deck):
    value = deck.get('bruno')
    return deepcopy(value) if isinstance(value, dict) else {}


def writable_deck(col, deck):
    if deck is None or deck['dyn']:
        return False
    return not col.decks.children(deck['id'])


def owned_deck(col, deck_id, owner):
    deck = col.decks.get(deck_id, default=False)
    if not writable_deck(col, deck) or metadata(deck).get('owner') != owner:
        raise Rejected('Stapel nicht gefunden.', 404)
    return deck


def save_deck(col, deck, meta):
    description, previous = deck.get('desc', ''), metadata(deck)
    if not description or description == html.escape(previous.get('lesson', '')).replace('\n', '<br>'):
        deck['desc'] = html.escape(meta.get('lesson', '')).replace('\n', '<br>')
    deck['bruno'] = meta
    col.decks.update(deck, preserve_usn=False)


def mark_vocabulary_decks(col):
    total, updated = 0, 0
    for deck in col.decks.all():
        if deck['dyn']:
            continue
        total += 1
        meta = metadata(deck)
        if meta.get('vocabulary') is not True:
            meta['vocabulary'] = True
            deck['bruno'] = meta
            col.decks.update(deck, preserve_usn=False)
            updated += 1
    return {'total': total, 'updated': updated}


def deck_list(col, owner):
    tree = col.sched.deck_due_tree()
    decks = []
    for node in tree.children if tree else ():
        if node.children or node.filtered:
            continue
        deck = col.decks.get(node.deck_id, default=False)
        if deck is None:
            continue
        meta = metadata(deck)
        if meta.get('owner') != owner:
            continue
        decks.append({
            'id': deck['id'],
            'name': deck['name'],
            'total': node.total_in_deck,
            'due': node.learn_count + node.review_count + node.new_count,
            'vocabulary': meta.get('vocabulary', False),
            'deletable': deck['id'] != DEFAULT_DECK_ID,
        })
    return sorted(decks, key=lambda deck: deck['name'].casefold())


class PlainText(HTMLParser):
    def __init__(self, value):
        super().__init__()
        self.pieces = []
        self.images = []
        self.ignored = 0
        self.feed(value)
        self.close()
        self.text = AV_MARKER.sub('', ''.join(self.pieces)).strip()

    def handle_starttag(self, tag, attrs):
        if tag in {'style', 'script', 'template'}:
            self.ignored += 1
        if tag in {'br', 'li', 'p', 'div', 'tr'} and not self.ignored:
            self.pieces.append('\n')
        if tag == 'img' and not self.ignored:
            image = dict(attrs)
            self.images.append(unquote(image.get('src') or ''))
            if image.get('alt'):
                self.pieces.append(image['alt'])

    def handle_endtag(self, tag):
        if tag in {'style', 'script', 'template'} and self.ignored:
            self.ignored -= 1
        if tag in {'li', 'p', 'div', 'tr'} and not self.ignored:
            self.pieces.append('\n')

    def handle_data(self, data):
        if not self.ignored:
            self.pieces.append(data)


def clean(value):
    return PlainText(value).text


@contextmanager
def selected_deck(col, deck_id):
    previous = col.decks.get_current_id()
    if previous != deck_id:
        col.decks.set_current(deck_id)
    try:
        yield
    finally:
        if previous != deck_id:
            col.decks.set_current(previous)


def media_type(file):
    if not file or len(file.encode()) > 255 or re.search(r'[\\/:\x00-\x1f\x7f]', file):
        return None
    return MEDIA_TYPES.get(file.rsplit('.', maxsplit=1)[-1].lower()) if '.' in file else None


def card_face(value, tags):
    parser = PlainText(value)
    files = dict.fromkeys(
        parser.images + [tag.filename for tag in tags if hasattr(tag, 'filename')] + AV_MARKER.findall(value)
    )
    media = []
    for file in files:
        if mime := media_type(file):
            media.append({'file': file, 'kind': mime.split('/')[0]})
    return parser.text, media


def card_content(card):
    display = card.render_output()
    note = card.note()
    _, note_meta = unpack(note.fields[1]) if len(note.fields) == 2 else ('', {})
    question_markup = MNEMONIC_MARKUP.sub('', display.question_text) if note_meta else display.question_text
    answer_markup = MNEMONIC_MARKUP.sub('', display.answer_text) if note_meta else display.answer_text
    answer_markup = ANSWER_SEPARATOR.split(answer_markup, maxsplit=1)[-1]
    if answer_markup.startswith(question_markup):
        answer_markup = answer_markup[len(question_markup):]
    typed = TYPE_ANSWER.search(answer_markup)
    question, question_media = card_face(TYPE_ANSWER.sub('', question_markup), display.question_av_tags)
    answer, answer_media = card_face(TYPE_ANSWER.sub('', answer_markup), display.answer_av_tags)
    if typed and not answer and typed[1] in note:
        answer, typed_media = card_face(MNEMONIC_MARKUP.sub('', note[typed[1]]), ())
        answer_media.extend(item for item in typed_media if item not in answer_media)
    return {
        'question': question, 'questionMedia': question_media,
        'answer': answer, 'answerMedia': [item for item in answer_media if item not in question_media],
        'hint': note_meta.get('hint', ''), 'image': note_meta.get('image', ''),
        'imageState': mnemonic_image_job(note_meta).get('state', ''),
    }


def study(col, deck):
    meta = metadata(deck)
    result = {
        'deck': deck['id'], 'name': deck['name'], 'card': None,
        'vocabulary': meta.get('vocabulary') is True, 'focus': meta.get('focus', ''),
    }
    with selected_deck(col, deck['id']):
        queue = col.sched.get_queued_cards()
        if not queue.cards:
            return result
        queued = queue.cards[0]
        card = col.get_card(queued.card.id)
        if card.did != deck['id']:
            raise Rejected('Die Karte wurde in Anki verschoben. Bitte den Stapel neu öffnen.', 409)
        result['card'] = card_content(card) | {
            'id': card.id, 'reps': card.reps, 'modified': card.mod,
            'intervals': list(col.sched.describe_next_states(queued.states)),
            'mnemonicState': card_mnemonic_state(col, deck, card),
        }
    return result


def answer_card(col, deck, request):
    with selected_deck(col, deck['id']):
        card = col.sched.getCard()
        if not card or (card.did, card.id, card.reps, card.mod) != (
            deck['id'], request['card'], request['reps'], request['modified'],
        ):
            raise Rejected('Diese Karte ist nicht mehr aktuell. Bitte die Lernrunde neu öffnen.', 409)
        card.timer_started -= max(0, min(request['elapsed'], 120))
        col.sched.answerCard(card, request['rating'])


def save_media(col, deck_id, extension, content):
    digest = hashlib.sha256(content).hexdigest()[:32]
    return col.media.write_data(f'bruno-{deck_id}-{digest}.{extension}', content)


def save_note(col, note, back, meta):
    markup = ''
    if meta.get('hint'):
        hint = html.escape(meta['hint'])
        markup = f'<div><strong>Eselsbrücke:</strong> {hint}'
        if meta.get('image'):
            markup += f'<br><img src="{html.escape(meta["image"], quote=True)}" alt="Illustration zur Eselsbrücke">'
        markup += '</div>'
        templates = note.note_type()['tmpls']
        answer_field = re.escape(note.keys()[1])
        if len(templates) != 1 or re.search(r'{{[^{}]*\b' + answer_field + r'\s*}}', templates[0]['qfmt']):
            # Anki still tracks media references inside comments, without showing them on question faces.
            markup = f'<!--{markup}-->'
    note.fields[1] = pack(back, meta) + markup
    col.update_note(note)


def note_deck(col, note_id):
    return col.db.scalar('''
        select min(deck) from (select coalesce(nullif(odid, 0), did) as deck from cards where nid = ?)
        having min(deck) = max(deck)
    ''', note_id)


def vocabulary_pair(note):
    if note.keys() not in (['Front', 'Back'], ['Vorderseite', 'Rückseite']):
        return None
    front, back = clean(note.fields[0]), clean(unpack(note.fields[1])[0])
    if front and back and len(front) <= 500 and len(back) <= 1500:
        return {'front': front, 'back': back}
    return None


def mnemonic_state(note_meta):
    if note_meta.get('hint'):
        return ''
    if note_meta.get('mnemonicRetryAt'):
        return 'queued'
    return note_meta.get('mnemonicState', 'queued')


def mnemonic_image_job(note_meta):
    if note_meta.get('image'):
        return {'state': 'ready'}
    job = note_meta.get('imageJob', {})
    if not job and (hint := note_meta.get('hint')):
        return {'state': 'queued', 'prompt': 'Bilde die konkrete Szene dieses Merksatzes ab: ' + hint}
    return job


def card_mnemonic_state(col, deck, card):
    if not metadata(deck).get('vocabulary'):
        return ''
    note = card.note()
    if not vocabulary_pair(note) or note_deck(col, note.id) != deck['id']:
        return ''
    _, note_meta = unpack(note.fields[1])
    return mnemonic_state(note_meta)


def ensure_deck_name_available(col, title, deck_id=None):
    existing = col.decks.by_name(title)
    if existing and existing['id'] != deck_id:
        raise Rejected('Der Name ist in Anki bereits belegt. Bitte einen anderen Titel wählen.', 409)


def generate(col, owner, request):
    deck_id, title = request['deck'], request['title']
    deck = owned_deck(col, deck_id, owner) if deck_id else None
    if not deck:
        ensure_deck_name_available(col, title)
    if deck and (deck['name'] != title or lesson(col, deck)['text'] != request['previousLesson']):
        raise Rejected('Der Stapel wurde seit der Vorschau geändert. Bitte einen neuen Entwurf erstellen.', 409)
    meta = metadata(deck) if deck else {'owner': owner}
    if meta.get('vocabulary', request['vocabulary']) != request['vocabulary']:
        raise Rejected('Der Kartentyp muss zum bestehenden Stapel passen.', 422)

    basic_models = [
        model for model in col.models.all()
        if [field['name'] for field in model['flds']] in (['Front', 'Back'], ['Vorderseite', 'Rückseite'])
        and len(model['tmpls']) == 1 and model['type'] == 0
    ]
    basic = None
    existing = set()
    if deck:
        for note_id in col.find_notes(f'did:{deck_id}'):
            note = col.get_note(note_id)
            if not basic:
                basic = next((model for model in basic_models if model['id'] == note.mid), None)
            if not basic or note.mid != basic['id']:
                raise Rejected('Dieser Stapel verwendet einen anderen Anki-Notiztyp. Bitte separat erstellen.', 422)
            existing.add((clean(note.fields[0]).casefold(), clean(unpack(note.fields[1])[0]).casefold()))
    items = []
    for item in request['cards']:
        pair = (item['front'].casefold(), item['back'].casefold())
        if pair in existing:
            continue
        existing.add(pair)
        items.append(item)

    if (items or not meta.get('lesson')) and meta.get('lesson') != request['lesson']:
        if any(job.get('state') in {'queued', 'submitting', 'working'} for job in meta.get('jobs', {}).values()):
            raise Rejected('Bitte warten, bis die Medien des Stapels fertig sind.', 409)
        for field in ('jobs', 'audio', 'video'):
            meta.pop(field, None)
        meta['lesson'] = request['lesson']

    documents = [(document, base64.b64decode(document['data'], validate=True)) for document in request['documents']]
    undo = col.add_custom_undo_entry('bruno: Stapel pflegen')
    new_files = []
    try:
        if not basic:
            basic = basic_models[0] if basic_models else get_stock_notetypes(col)[0][1](col)
            if not basic_models:
                col.models.add(basic)
        if not deck:
            deck_id = col.decks.add_normal_deck_with_name(title).id
            deck = col.decks.get(deck_id)

        notes = []
        for item in items:
            note = col.new_note(basic)
            note.fields[0] = html.escape(item['front']).replace('\n', '<br>')
            back = html.escape(item['back']).replace('\n', '<br>')
            note.tags = ['bruno_vocab' if request['vocabulary'] else 'bruno']
            note.fields[1] = back
            notes.append(AddNoteRequest(note, deck_id))
        if notes:
            col.add_notes(notes)

        meta['vocabulary'] = request['vocabulary']
        meta['focus'] = request.get('focus', '')
        for document, document_bytes in documents:
            file = save_media(col, deck_id, document['extension'], document_bytes)
            if not any(source['file'] == file for source in meta.get('sources', [])):
                new_files.append(file)
                meta.setdefault('sources', []).append({'type': document['type'], 'file': file})
        save_deck(col, deck, meta)
    except Exception:
        col.merge_undo_entries(undo)
        col.undo()
        if new_files:
            col.media.trash_files(new_files)
        raise
    col.merge_undo_entries(undo)
    return {'id': deck_id, 'added': len(items)}


def lesson(col, deck):
    meta = metadata(deck)
    text = meta.get('lesson') or clean(deck.get('desc', ''))
    if not text:
        examples = []
        for card_id in col.find_cards(f'did:{deck["id"]}')[:8]:
            content = card_content(col.get_card(card_id))
            if content['question'] and content['answer']:
                examples.append(f'{content["question"]} — {content["answer"]}')
        text = '\n\n'.join(examples)[:6000]
    return {
        'id': deck['id'], 'name': deck['name'],
        'text': text, 'audio': meta.get('audio', ''),
        'video': meta.get('video', ''), 'sources': meta.get('sources', []),
        'vocabulary': meta.get('vocabulary'),
        'jobs': {kind: job['state'] for kind, job in meta.get('jobs', {}).items()},
    }


def queue_video(col, deck, model):
    meta = metadata(deck)
    text = lesson(col, deck)['text']
    if not text:
        raise Rejected('Für diesen Stapel liegt noch kein Lerntext vor.', 422)
    jobs = meta.setdefault('jobs', {})
    if jobs.get('video', {}).get('state') in {'queued', 'submitting', 'working', 'unknown'}:
        raise Rejected('Das Video wurde bereits beauftragt.', 409)
    jobs['video'] = {'state': 'queued', 'prompt': text, 'model': model}
    save_deck(col, deck, meta)


def retry_image(col, deck, card_id):
    if col.db.scalar('select did from cards where id = ?', card_id) != deck['id']:
        raise Rejected('Karte nicht gefunden.', 404)
    note = col.get_card(card_id).note()
    if len(note.fields) != 2 or note_deck(col, note.id) != deck['id']:
        raise Rejected('Für diese Karte gibt es keinen Bildauftrag.', 422)
    back, meta = unpack(note.fields[1])
    job = meta.get('imageJob', {})
    if job.get('state') != 'failed' or not job.get('prompt'):
        raise Rejected('Nur bestätigte Fehlschläge lassen sich erneut beauftragen.', 409)
    meta['imageJob'] = {'state': 'queued', 'prompt': job['prompt']}
    save_note(col, note, back, meta)


def media_path(col, deck, file, card_id):
    meta = metadata(deck)
    source = bool(MEDIA_NAME.fullmatch(file)) and any(item['file'] == file for item in meta.get('sources', []))
    mime = 'application/octet-stream' if source else media_type(file)
    if mime is None and file.endswith('.svg') and MEDIA_NAME.fullmatch(file):
        mime = 'image/svg+xml'
    if mime is None:
        raise Rejected('Datei nicht gefunden.', 404)
    linked = source or file in {meta.get('audio'), meta.get('video')}
    if card_id and not linked and col.db.scalar('select did from cards where id = ?', card_id) == deck['id']:
        card = card_content(col.get_card(card_id))
        linked = file == card['image'] or any(
            item['file'] == file for item in card['questionMedia'] + card['answerMedia']
        )
    if not linked:
        raise Rejected('Datei nicht gefunden.', 404)
    path = Path(col.media.dir()) / file
    if not path.is_file() or path.is_symlink():
        raise Rejected('Datei nicht gefunden. Eventuell läuft der Medienabgleich noch.', 404)
    return {'path': str(path), 'attachment': source, 'mime': mime}
