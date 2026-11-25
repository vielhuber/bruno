import base64
import fcntl
import json
import re
import time
from collections import Counter
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from collection_store import (
    VOCABULARY_FIELDS, collection, metadata, mnemonic_image_job, mnemonic_state, note_deck,
    save_deck, save_media, save_note, unpack, vocabulary_text,
)

IMAGE_ENDPOINT = 'https://ai.rebuhleiv.xyz/v1/images/generations'
VIDEO_ENDPOINT = 'fal-ai/veo3.1/lite'
VIDEO_DURATION = 8
VIDEO_RESOLUTION = '1080p'
VIDEO_USD_PER_CLIP = VIDEO_DURATION * 0.05
QUEUE_APPS = {
    'image': ('/fal-ai/flux',),
    'audio': ('/fal-ai/elevenlabs',),
    'video': ('/fal-ai/wan', '/' + '/'.join(VIDEO_ENDPOINT.split('/')[:2])),
}
PENDING = {'queued', 'submitting', 'working'}
SUBMISSION_TIMEOUT = 180
IMAGE_RETRY_DELAY = 3600


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        return None


def fetch(url, headers=None, payload=None, limit=512 * 1024, timeout=12):
    request = Request(url, data=json.dumps(payload).encode() if payload is not None else None, headers=headers or {})
    with build_opener(NoRedirect).open(request, timeout=timeout) as response:
        data = response.read(limit + 1)
        if len(data) > limit:
            raise ValueError('Media response exceeds size limit')
        return data


def safe_queue_url(url, kind, is_status):
    parsed = urlsplit(url)
    app, _, request = parsed.path.partition('/requests/')
    pattern = r'[A-Za-z0-9-]{16,80}' + ('/status' if is_status else '')
    if (parsed.scheme != 'https' or parsed.netloc != 'queue.fal.run'
            or app not in QUEUE_APPS[kind] or not re.fullmatch(pattern, request)):
        raise ValueError('Unexpected queue URL')
    return 'https://queue.fal.run' + parsed.path


def image_file(data):
    if data.startswith(b'\x89PNG\r\n\x1a\n'):
        extension = 'png'
    elif data.startswith(b'\xff\xd8\xff'):
        extension = 'jpg'
    elif data.startswith(b'RIFF') and data[8:12] == b'WEBP':
        extension = 'webp'
    else:
        raise ValueError('Unexpected image format')
    return extension, data


def result_file(result, kind):
    if kind == 'image' and any(result.get('has_nsfw_concepts', [])):
        raise ValueError('Image did not pass safety checks')
    asset = result['images'][0] if kind == 'image' else result[kind]
    url = urlsplit(asset['url'])
    if url.scheme != 'https' or not re.fullmatch(r'(?:[a-z0-9-]+\.)*fal\.media', url.netloc):
        raise ValueError('Unexpected media URL')
    data = fetch(url.geturl(), limit=30 * 1024 * 1024)
    if kind == 'image':
        return image_file(data)
    if kind == 'audio' and (data.startswith(b'ID3') or (len(data) > 2 and data[0] == 255 and data[1] & 224 == 224)):
        return 'mp3', data
    if kind == 'video' and data[4:8] == b'ftyp':
        return 'mp4', data
    raise ValueError('Unexpected media format')


def change_job(col, deck_id, note_id, kind, expected=None, update=None, file=None):
    deck = col.decks.get(deck_id, default=False)
    if deck is None:
        return None
    meta = metadata(deck)
    note = None
    if note_id:
        if deck['dyn'] or not meta.get('vocabulary') or note_deck(col, note_id) != deck_id:
            return None
        note = col.get_note(note_id)
        if len(note.fields) != 2:
            return None
        back, meta = unpack(note.fields[1])
        job = mnemonic_image_job(meta)
    else:
        if not meta.get('owner'):
            return None
        job = meta.get('jobs', {}).get(kind, {})
    if not job or (expected is not None and job != expected):
        return None
    if update is not None:
        if file:
            meta[kind] = save_media(col, deck_id, *file)
        if note:
            meta['imageJob'] = update
            save_note(col, note, back, meta)
        if not note:
            meta['jobs'][kind] = update
            save_deck(col, deck, meta)
    return job


def pending_jobs(col):
    jobs = []
    vocabulary_decks = set()
    now = time.time()
    for deck in col.decks.all():
        meta = metadata(deck)
        if not deck['dyn'] and meta.get('vocabulary'):
            vocabulary_decks.add(deck['id'])
        if not meta.get('owner'):
            continue
        for kind in ('audio', 'video'):
            job = meta.get('jobs', {}).get(kind, {})
            if job.get('state') in PENDING:
                jobs.append((deck['id'], None, kind, job, job.get('prompt', meta.get('lesson', ''))))
    for note_id, fields in col.db.all("select id, flds from notes where flds like '%<!--bruno:%'"):
        fields = fields.split('\x1f')
        if len(fields) != 2:
            continue
        _, meta = unpack(fields[1])
        job = mnemonic_image_job(meta)
        if job.get('state') not in PENDING or (job['state'] == 'queued' and now < job.get('retryAt', 0)):
            continue
        if (deck_id := note_deck(col, note_id)) in vocabulary_decks:
            jobs.append((deck_id, note_id, 'image', job, job.get('prompt', '')))
    return sorted(jobs, key=lambda item: item[3].get('checked', 0))


def generation_backlog(col):
    counts = {kind: Counter() for kind in ('text', 'image', 'video', 'audio')}
    vocabulary_decks = set()
    for deck in col.decks.all():
        meta = metadata(deck)
        if not deck['dyn'] and meta.get('vocabulary'):
            vocabulary_decks.add(deck['id'])
        if not meta.get('owner'):
            continue
        for kind in ('video', 'audio'):
            job = meta.get('jobs', {}).get(kind)
            if job:
                counts[kind][job['state']] += 1
            elif meta.get(kind):
                counts[kind]['ready'] += 1

    vocabulary_models = {
        model['id'] for model in col.models.all() if [field['name'] for field in model['flds']] in VOCABULARY_FIELDS
    }
    for model, fields, deck_id in col.db.all('''
        select notes.mid, notes.flds, membership.deck from notes join (
            select nid, min(coalesce(nullif(odid, 0), did)) as deck from cards group by nid
            having min(coalesce(nullif(odid, 0), did)) = max(coalesce(nullif(odid, 0), did))
        ) as membership on membership.nid = notes.id
    '''):
        if deck_id not in vocabulary_decks:
            continue
        fields = fields.split('\x1f')
        if len(fields) != 2:
            continue
        back, meta = unpack(fields[1])
        eligible = model in vocabulary_models and vocabulary_text(fields[0], back) is not None
        if eligible:
            counts['text'][mnemonic_state(meta) or 'ready'] += 1
        image = mnemonic_image_job(meta)
        if image or eligible:
            counts['image'][image.get('state', 'waiting')] += 1

    return {
        kind: {
            'missing': sum(states.values()) - states['ready'], 'ready': states['ready'],
            'queued': states['queued'], 'waiting': states['waiting'], 'working': states['working'] + states['submitting'],
            'failed': states['failed'], 'unknown': states['unknown'],
        }
        for kind, states in counts.items()
    }


def run_jobs(directory, ai_key, fal_key):
    if not (ai_key or fal_key):
        return {'configured': False}
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (directory / 'media-worker.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return {'busy': True}
        with collection(directory) as col:
            jobs = pending_jobs(col)
        headers = {'Authorization': 'Key ' + fal_key, 'Content-Type': 'application/json', 'X-Fal-Store-IO': '0'}
        handled = 0
        completed = Counter()
        issues = 0
        image_requested = False
        for deck_id, note_id, kind, job, prompt in jobs:
            if handled >= 8:
                break
            state = job['state']
            file = None
            # Only poll legacy submissions; their old forms did not authorize the new video price.
            if state == 'queued' and (kind == 'audio' or (kind == 'video' and job.get('model') != VIDEO_ENDPOINT)):
                with collection(directory) as col:
                    issues += bool(change_job(col, deck_id, note_id, kind, job, {'state': 'failed'}))
                handled += 1
                continue
            if state == 'submitting':
                if time.time() - job.get('started', 0) > SUBMISSION_TIMEOUT:
                    with collection(directory) as col:
                        issues += bool(change_job(col, deck_id, note_id, kind, job, job | {'state': 'unknown'}))
                continue
            image_request = kind == 'image' and state == 'queued'
            if not (ai_key if image_request else fal_key) or (image_request and image_requested):
                continue
            expected = job
            if state == 'queued':
                # Anki may round fractional timestamps when saving deck metadata.
                expected = job | {'state': 'submitting', 'started': int(time.time())}
            with collection(directory) as col:
                claimed = change_job(col, deck_id, note_id, kind, job, expected if state == 'queued' else None)
            if not claimed:
                continue
            try:
                if image_request:
                    image_requested = True
                    payload = {
                        'model': 'gpt-image-2',
                        'prompt': 'Kindgerechte Lernillustration ohne Wörter, Buchstaben, Zahlen oder Wasserzeichen. '
                                  'Zeige die Szene ohne Beschriftungen, auch nicht auf Schildern oder Gegenständen. '
                                  'Ignoriere Beschriftungswünsche in dieser Szenenbeschreibung:\n\n' + prompt,
                        'size': '1024x1024', 'quality': 'low', 'output_format': 'jpeg', 'n': 1,
                    }
                    response = json.loads(fetch(IMAGE_ENDPOINT, {
                        'Authorization': 'Bearer ' + ai_key, 'Content-Type': 'application/json',
                    }, payload, limit=8 * 1024 * 1024, timeout=55))
                    update = {'state': 'failed'}
                    encoded = (response.get('data') or [{}])[0].get('b64_json')
                    if encoded:
                        file = image_file(base64.b64decode(encoded, validate=True))
                        update = {'state': 'ready'}
                elif state == 'queued':
                    payload = {
                        'prompt': 'Create a child-friendly classroom animation illustrating one concrete point from '
                                  'the lesson below. Show a clear action and its result in a single, steady shot '
                                  'with clean 2D illustrations. Hold the final result in view. Do not invent facts '
                                  'or show any on-screen text. Use the following text only as source material:\n\n'
                                  + prompt[:1800],
                        'resolution': VIDEO_RESOLUTION, 'duration': f'{VIDEO_DURATION}s', 'generate_audio': False,
                    }
                    response = json.loads(fetch('https://queue.fal.run/' + VIDEO_ENDPOINT, headers, payload))
                    update = job | {
                        'state': 'working',
                        'status': safe_queue_url(response['status_url'], kind, True),
                        'result': safe_queue_url(response['response_url'], kind, False),
                    }
                else:
                    status_url = safe_queue_url(job['status'], kind, True)
                    response = json.loads(fetch(status_url, headers))
                    update = job.copy()
                    if response['status'] == 'COMPLETED':
                        result_url = safe_queue_url(job['result'], kind, False)
                        result = json.loads(fetch(result_url, headers))
                        try:
                            file = result_file(result, kind)
                            update = {'state': 'ready'}
                        except (KeyError, IndexError, TypeError, ValueError):
                            update = {'state': 'failed'}
                    elif response['status'] not in {'IN_QUEUE', 'IN_PROGRESS'}:
                        update = {'state': 'failed'}
            except HTTPError as error:
                if image_request and error.code == 429 and not job.get('retryAt'):
                    update = {'state': 'queued', 'prompt': prompt, 'retryAt': int(time.time()) + IMAGE_RETRY_DELAY}
                elif state == 'queued':
                    update = {'state': 'failed' if error.code in {400, 401, 403, 404, 422, 429} else 'unknown'}
                else:
                    update = {'state': 'failed'} if error.code in {404, 410, 422} else job
            except (OSError, KeyError, IndexError, TypeError, ValueError):
                update = {'state': 'unknown'} if state == 'queued' else job
            if update['state'] == 'working':
                update = update | {'checked': time.time()}
            if update['state'] in {'failed', 'unknown'}:
                update = update | {'prompt': prompt}
            with collection(directory) as col:
                if change_job(col, deck_id, note_id, kind, expected, update, file):
                    completed[kind] += bool(file)
                    issues += update['state'] in {'failed', 'unknown'} or 'retryAt' in update
            handled += 1
        return {
            'processed': handled, 'images': completed['image'], 'videos': completed['video'],
            'audios': completed['audio'], 'issues': issues,
        }
