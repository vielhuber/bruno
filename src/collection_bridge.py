import json
import os
import sys
import time
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

from anki.collection import SyncOutput
from anki.sync_pb2 import SyncAuth

from collection_store import (
    Rejected, answer_card, collection, deck_list, generate, lesson, media_path,
    metadata, owned_deck, queue_video, retry_image, save_deck, study, writable_deck,
)
from media_jobs import VIDEO_ENDPOINT, VIDEO_USD_PER_CLIP, run_jobs


def backup(col, directory, manual=False):
    target = directory / 'backups'
    target.mkdir(mode=0o700, exist_ok=True)
    now = datetime.now(ZoneInfo('Europe/Berlin'))
    day = now.date().isoformat()
    stamp = f'{day}-{now:%H%M%S-%f}-manual' if manual else day
    filename = target / f'{stamp}.colpkg'
    if not filename.exists():
        temporary = target / f'.{stamp}.partial'
        try:
            col.export_collection_package(str(temporary), include_media=True, legacy=False)
            temporary.replace(filename)
        finally:
            temporary.unlink(missing_ok=True)
    return {'date': day}


def sync(col, directory, request):
    key = directory / 'anki-sync.key'
    endpoint = request.get('endpoint') or None
    if request.get('password'):
        auth = col.sync_login(request['email'], request['password'], endpoint)
        if not auth.hkey:
            raise Rejected('AnkiWeb hat keinen Synchronisationsschlüssel geliefert.')
        pending = key.with_suffix('.new')
        pending.write_text(auth.hkey, encoding='utf-8')
        pending.replace(key)
        return {'connected': True}
    if not key.is_file():
        return {'configured': False}
    auth = SyncAuth(hkey=key.read_text(encoding='utf-8').strip(), endpoint=endpoint, io_timeout_secs=45)
    only_empty_default = (
        col.card_count() == 0 and col.note_count() == 0
        and all(deck['id'] == 1 and not deck.get('desc') for deck in col.decks.all())
        and not any(Path(col.media.dir()).iterdir())
    )
    result = col.sync_collection(auth, sync_media=True)
    if result.new_endpoint:
        auth.endpoint = result.new_endpoint
    if result.required in {SyncOutput.FULL_SYNC, SyncOutput.FULL_UPLOAD, SyncOutput.FULL_DOWNLOAD}:
        download = result.required == SyncOutput.FULL_DOWNLOAD and only_empty_default
        upload = result.required == SyncOutput.FULL_UPLOAD and request.get('initialUpload', False)
        if not (download or upload):
            raise Rejected('AnkiWeb verlangt einen vollständigen Abgleich. Keine Sammlung wurde überschrieben.', 409)
        col.close_for_full_sync()
        col.full_upload_or_download(auth=auth, server_usn=result.server_media_usn, upload=upload)
        col.reopen(after_full_sync=True)
    deadline = time.monotonic() + 240
    while col.media_sync_status().active:
        if time.monotonic() >= deadline:
            raise Rejected('Der Medienabgleich wurde nicht rechtzeitig fertig. Der nächste Cronlauf setzt ihn fort.', 503)
        time.sleep(0.5)
    return {'synced': True}


def dispatch(directory, request):
    action = request['action']
    if action == 'jobs':
        return run_jobs(directory, request['aiKey'], request['falKey'])
    with collection(directory) as col:
        if action == 'backup':
            return backup(col, directory, manual=request.get('manual', False))
        if action == 'sync':
            return sync(col, directory, request)
        owner = request['owner']
        if action == 'assign':
            deck = col.decks.by_name(request['name'])
            if not writable_deck(col, deck):
                raise Rejected('Nur reguläre Stapel ohne Unterstapel können zugewiesen werden.', 422)
            meta = metadata(deck)
            if meta.get('owner') not in {None, owner}:
                raise Rejected('Der Stapel ist bereits einem anderen Konto zugewiesen.', 409)
            meta['owner'] = owner
            save_deck(col, deck, meta)
            return {'assigned': deck['name']}
        if action == 'list':
            return deck_list(col, owner)
        if action == 'generate':
            return generate(col, owner, request)
        deck = owned_deck(col, request['deck'], owner)
        if action == 'study':
            return {'name': deck['name'], 'card': study(col, deck), 'vocabulary': metadata(deck).get('vocabulary') is True}
        if action == 'answer':
            return answer_card(col, deck, request)
        if action == 'lesson':
            material = lesson(col, deck)
            material['estimatedVideoCost'] = VIDEO_USD_PER_CLIP
            return material
        if action == 'queue-video':
            return queue_video(col, deck, VIDEO_ENDPOINT)
        if action == 'retry-image':
            return retry_image(col, deck, request['card'])
        if action == 'media':
            return media_path(col, deck, request['file'], request.get('card'))
        raise Rejected('Unbekannte Anki-Aktion.', 400)


if __name__ == '__main__':
    os.umask(0o077)
    try:
        result = dispatch(Path(sys.argv[1]), json.load(sys.stdin))
        print(json.dumps({'result': result}, ensure_ascii=False))
    except Rejected as error:
        message, status = error.args if len(error.args) > 1 else (str(error), 400)
        print(json.dumps({'error': message, 'status': status}))
    except Exception as error:
        print(json.dumps({'error': 'Anki konnte die Aktion nicht abschließen.', 'status': 503,
                          'diagnostic': type(error).__name__}))
