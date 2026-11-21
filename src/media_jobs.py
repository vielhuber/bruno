import base64
import fcntl
import json
import re
import time
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

from collection_store import collection, metadata, save_deck, save_media, save_note, unpack

IMAGE_ENDPOINT = 'https://ai.rebuhleiv.xyz/v1/images/generations'
ENDPOINTS = {
    'image': 'fal-ai/flux/schnell',  # only for previously submitted images
    'audio': 'fal-ai/elevenlabs/tts/turbo-v2.5',
    'video': 'fal-ai/wan/v2.2-a14b/text-to-video/turbo',
}
AUDIO_USD_PER_CHARACTER = 0.05 / 1000
VIDEO_RESOLUTION = '480p'
VIDEO_USD_PER_CLIP = 0.05
PENDING = {'queued', 'submitting', 'working'}
SUBMISSION_TIMEOUT = 180


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
    prefix = '/' + '/'.join(ENDPOINTS[kind].split('/')[:2])
    pattern = re.escape(prefix) + r'/requests/[A-Za-z0-9-]{16,80}' + ('/status' if is_status else '')
    if parsed.scheme != 'https' or parsed.netloc != 'queue.fal.run' or not re.fullmatch(pattern, parsed.path):
        raise ValueError('Unexpected queue URL')
    return 'https://queue.fal.run' + parsed.path


def image_file(data):
    if data.startswith(b'\x89PNG\r\n\x1a\n'):
        return 'png', data
    if data.startswith(b'\xff\xd8\xff'):
        return 'jpg', data
    if data.startswith(b'RIFF') and data[8:12] == b'WEBP':
        return 'webp', data
    raise ValueError('Unexpected image format')


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
    if deck is None or not metadata(deck).get('owner'):
        return None
    meta = metadata(deck)
    note = None
    if note_id:
        if col.db.scalar('select count(*) from cards where nid = ? and did = ?', note_id, deck_id) != 1:
            return None
        note = col.get_note(note_id)
        if len(note.fields) != 2:
            return None
        back, meta = unpack(note.fields[1])
        job = meta.get('imageJob', {})
    else:
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
    for deck in col.decks.all():
        meta = metadata(deck)
        if not meta.get('owner'):
            continue
        for kind in ('audio', 'video'):
            job = meta.get('jobs', {}).get(kind, {})
            if job.get('state') in PENDING:
                jobs.append((deck['id'], None, kind, job, job.get('prompt', meta.get('lesson', ''))))
    for note_id in col.find_notes('tag:bruno_vocab'):
        note = col.get_note(note_id)
        if len(note.fields) != 2:
            continue
        job = unpack(note.fields[1])[1].get('imageJob', {})
        if job.get('state') not in PENDING:
            continue
        cards = note.cards()
        if len(cards) == 1:
            jobs.append((cards[0].did, note_id, 'image', job, job.get('prompt', '')))
    return sorted(jobs, key=lambda item: item[3].get('checked', 0))


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
        image_requested = False
        for deck_id, note_id, kind, job, prompt in jobs:
            if handled >= 8:
                break
            state = job['state']
            file = None
            if state == 'submitting':
                if time.time() - job.get('started', 0) > SUBMISSION_TIMEOUT:
                    with collection(directory) as col:
                        change_job(col, deck_id, note_id, kind, job, job | {'state': 'unknown'})
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
                        'prompt': 'Kindgerechte Lernillustration ohne Schrift: ' + prompt,
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
                    if kind == 'audio':
                        payload = {'text': prompt, 'language_code': 'de', 'enable_safety_checker': True}
                    else:
                        payload = {'prompt': 'Kurze, freundliche, stumme Lern-Animation, kein eingeblendeter Text: '
                                             + prompt[:600], 'resolution': VIDEO_RESOLUTION, 'enable_safety_checker': True,
                                   'enable_output_safety_checker': True}
                    response = json.loads(fetch('https://queue.fal.run/' + ENDPOINTS[kind], headers, payload))
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
                if state == 'queued':
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
                change_job(col, deck_id, note_id, kind, expected, update, file)
            handled += 1
        return {'processed': handled}
