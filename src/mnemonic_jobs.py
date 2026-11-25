import time
from uuid import uuid4

from collection_store import (
    metadata, mnemonic_image_job, mnemonic_state, note_deck, save_note, unpack, vocabulary_pair,
)

BATCH_SIZE = 10
PENDING = {'queued', 'submitting', 'working'}
RETRY_DELAY = 3600


def deck_notes(col, deck):
    deck_id = deck['id']
    for note_id in col.db.list('''
        select distinct nid from cards where odid = ? or (did = ? and odid = 0) order by nid
    ''', deck_id, deck_id):
        if note_deck(col, note_id) != deck_id:
            continue
        note = col.get_note(note_id)
        if pair := vocabulary_pair(note):
            back, note_meta = unpack(note.fields[1])
            yield note, pair, back, note_meta


def mnemonic_progress(col, deck):
    progress = {'total': 0, 'texts': 0, 'ready': 0, 'pending': 0, 'failed': 0, 'unknown': 0}
    if not metadata(deck).get('vocabulary'):
        return progress
    for _, _, _, note_meta in deck_notes(col, deck):
        progress['total'] += 1
        progress['texts'] += bool(note_meta.get('hint'))
        progress['ready'] += bool(note_meta.get('image'))
        state = mnemonic_state(note_meta) or mnemonic_image_job(note_meta).get('state')
        progress['pending'] += state in PENDING
        progress['failed'] += state == 'failed'
        progress['unknown'] += state == 'unknown'
    return progress


def claim_mnemonics(col):
    batch = uuid4().hex
    claimed = []
    now = int(time.time())
    for deck in reversed(col.decks.all()):
        if deck['dyn'] or not metadata(deck).get('vocabulary'):
            continue
        for note, pair, back, note_meta in deck_notes(col, deck):
            state = mnemonic_state(note_meta)
            if state == 'working':
                note_meta.update(mnemonicState='failed')
                note_meta.pop('mnemonicBatch', None)
                save_note(col, note, back, note_meta)
            if state != 'queued' or now < note_meta.get('mnemonicRetryAt', 0):
                continue
            if note_meta.pop('mnemonicRetryAt', None):
                note_meta['mnemonicRetried'] = True
            note_meta.update(mnemonicState='working', mnemonicBatch=batch)
            save_note(col, note, back, note_meta)
            claimed.append(pair | {'note': note.id, 'deck': deck['id']})
            if len(claimed) == BATCH_SIZE:
                return {'id': batch, 'cards': claimed}
        if claimed:
            break
    return {'id': batch, 'cards': claimed}


def finish_mnemonics(col, batch, results):
    saved = 0
    issues = 0
    for index, item in enumerate(batch['cards']):
        deck_id, note_id = item['deck'], item['note']
        deck = col.decks.get(deck_id, default=False)
        if deck is None or deck['dyn'] or not metadata(deck).get('vocabulary') or note_deck(col, note_id) != deck_id:
            continue
        note = col.get_note(note_id)
        if len(note.fields) != 2:
            continue
        back, note_meta = unpack(note.fields[1])
        if note_meta.get('mnemonicBatch') != batch['id'] or note_meta.get('hint'):
            continue
        note_meta.pop('mnemonicBatch')
        result = results.get(str(index + 1))
        if result and vocabulary_pair(note) == {'front': item['front'], 'back': item['back']}:
            note_meta.pop('mnemonicState', None)
            note_meta.pop('mnemonicRetried', None)
            note_meta['hint'] = result['hint']
            if not note_meta.get('image') and not note_meta.get('imageJob'):
                note_meta['imageJob'] = {'state': 'queued', 'prompt': result['imagePrompt']}
            note.add_tag('bruno_vocab')
            saved += 1
        else:
            issues += 1
            note_meta['mnemonicState'] = 'failed'
            if not note_meta.get('mnemonicRetried'):
                note_meta['mnemonicRetryAt'] = int(time.time()) + RETRY_DELAY
        save_note(col, note, back, note_meta)
    return {'mnemonics': saved, 'issues': issues}
