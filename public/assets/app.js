let launchConfetti;
let ratingAudio;

function celebrateReview(rating) {
  if (
    !window.confetti ||
    window.matchMedia("(prefers-reduced-motion: reduce)").matches ||
    (rating <= 2 && !window.OffscreenCanvas?.prototype.transferToImageBitmap)
  ) {
    return;
  }
  launchConfetti ??= window.confetti.create(null, {
    resize: true,
    useWorker: false,
    disableForReducedMotion: true,
  });
  if (rating <= 2) {
    let retry = rating === 1;
    let scalar = 1.8;
    launchConfetti({
      particleCount: retry ? 60 : 15,
      spread: retry ? 135 : 66,
      startVelocity: retry ? 42 : 30,
      ticks: retry ? 145 : 100,
      origin: { x: 0.5, y: 0.68 },
      scalar,
      shapes: [
        window.confetti.shapeFromText({ text: retry ? "👻" : "💪", scalar }),
      ],
    });
    return;
  }
  let colors = ["#c4b3ff", "#a9ebce", "#ffcf7d", "#ff9fbf", "#80ddff"];
  if (rating === 3) {
    launchConfetti({
      particleCount: 24,
      spread: 58,
      startVelocity: 30,
      ticks: 90,
      origin: { x: 0.5, y: 0.68 },
      colors,
    });
    return;
  }
  for (let [x, angle] of [
    [0.05, 65],
    [0.95, 115],
  ]) {
    launchConfetti({
      particleCount: 135,
      angle,
      spread: 68,
      startVelocity: 57,
      ticks: 160,
      origin: { x, y: 0.7 },
      colors,
    });
  }
  launchConfetti({
    particleCount: 90,
    spread: 115,
    startVelocity: 38,
    gravity: 0.86,
    ticks: 180,
    scalar: 1.13,
    origin: { x: 0.5, y: 0.68 },
    shapes: ["star", "circle"],
    colors,
  });
}

function unlockRatingSound() {
  if (!window.AudioContext) return;
  try {
    ratingAudio ??= new AudioContext();
    if (ratingAudio.state !== "running") ratingAudio.resume().catch(() => {});
  } catch {
    // A blocked audio context must not prevent a rating from being saved.
  }
}

function playRating(rating) {
  let notes = [
    [330, 220, 165],
    [220, 277],
    [523, 659, 784],
    [523, 659, 784, 1047],
  ][rating - 1];
  if (notes && ratingAudio?.state === "running") {
    let start = ratingAudio.currentTime;
    for (let [index, frequency] of notes.entries()) {
      let time = start + index * 0.085;
      let oscillator = ratingAudio.createOscillator();
      let gain = ratingAudio.createGain();
      oscillator.type = rating === 1 ? "sine" : "triangle";
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0, time);
      gain.gain.linearRampToValueAtTime(0.095, time + 0.018);
      gain.gain.exponentialRampToValueAtTime(0.001, time + 0.17);
      oscillator.connect(gain).connect(ratingAudio.destination);
      oscillator.start(time);
      oscillator.stop(time + 0.18);
    }
  }
  celebrateReview(rating);
}

function setActionLabel($action, label, emoji) {
  $action.querySelector(".action-label").textContent = label;
  for (let $decoration of $action.querySelectorAll(".action-emoji")) {
    $decoration.textContent = emoji;
  }
}

class Study {
  static SELECTOR = "[data-study]";
  static NEXT_AUDIO_KEY = "bruno-next-audio";
  static MALE_VOICES = {
    de: /\b(?:markus|yannick|martin|stefan|conrad|michael|karsten)\b/i,
    en: /\b(?:alex|daniel|tom|david|mark|guy|george|ryan|james|richard|sean|ravi)\b/i,
    es: /\b(?:pablo|jorge|álvaro|alvaro|raul|diego)\b/i,
    fr: /\b(?:thomas|henri|paul|daniel|claude|guillaume)\b/i,
    it: /\b(?:cosimo|luca)\b/i,
  };

  constructor($root) {
    this.$root = $root;
    this.events = new AbortController();
  }

  ready(startRecording) {
    this.$solution = this.$root.querySelector("#solution");
    this.$actions = this.$root.querySelector("[data-answer-actions]");
    this.$reveal = this.$root.querySelector("[data-reveal]");
    this.$ratings = this.$root.querySelector("[data-ratings]");
    this.$form = this.$root.querySelector("[data-grade]");
    this.$feedback = this.$root.querySelector("[data-feedback]");
    this.$hint = this.$root.querySelector("[data-study-hint]");
    this.$mnemonic = this.$root.querySelector("[data-study-mnemonic]");
    this.hintUsed = this.$hint?.disabled ?? false;
    this.$recordStatus = this.$root.querySelector("[data-record-status]");
    let signal = this.events.signal;
    let stopMedia = () => {
      window.speechSynthesis?.cancel();
      this.cancelCapture?.();
    };
    window.addEventListener("pagehide", stopMedia, { signal });
    signal.addEventListener("abort", stopMedia, { once: true });
    this.$reveal.addEventListener("click", () => {
      this.reveal();
      this.$ratings.hidden = false;
      this.$feedback.hidden = true;
      if (this.$recordStatus) this.$recordStatus.textContent = "";
    });
    this.$ratings.addEventListener("submit", (event) => {
      event.preventDefault();
      if (event.submitter && !event.submitter.disabled) {
        this.saveRating(event.submitter);
      }
    });
    this.$hint?.addEventListener("click", async () => {
      let $status = this.$root.querySelector("#study-hint");
      this.setBusy(true);
      try {
        let payload = new FormData(this.$ratings);
        payload.set("verb", "study-hint");
        let response = await fetch(window.location.href, {
          method: "POST",
          body: payload,
        });
        let result = await response.json();
        if (!response.ok) {
          $status.textContent = result.error;
          return;
        }
        this.hintUsed = true;
        this.$ratings.querySelector('[name="rating"][value="4"]').disabled =
          true;
        $status.textContent = `${result.letter}...`;
        if (this.$mnemonic) this.$mnemonic.hidden = false;
      } catch {
        $status.textContent =
          "Der Tipp konnte nicht angezeigt werden. Bitte erneut versuchen.";
      } finally {
        this.setBusy(false);
      }
    });
    let speech = window.speechSynthesis;
    if (speech && typeof SpeechSynthesisUtterance === "function") {
      speech.getVoices();
      for (let $speak of this.$root.querySelectorAll("[data-speak]")) {
        let $face = $speak.closest("[data-speech-face]");
        let $speechText = $face.querySelector("[data-speech-text]");
        let $speechStatus = $face.querySelector("[data-speech-status]");
        $speak.hidden = false;
        $speak.addEventListener("click", () => {
          $speechStatus.textContent = "";
          speech.cancel();
          let utterance = new SpeechSynthesisUtterance($speechText.textContent);
          utterance.lang =
            $speak.dataset.speak || document.documentElement.lang;
          let language = utterance.lang.toLowerCase().split("-")[0];
          let voices = speech
            .getVoices()
            .filter(
              (voice) =>
                voice.localService &&
                voice.lang.toLowerCase().split("-")[0] === language,
            );
          let preferredVoice =
            voices.find(
              (voice) =>
                /(?:^|[\W_])(?:male|männlich)(?:$|[\W_])/i.test(
                  `${voice.name} ${voice.voiceURI}`,
                ) || Study.MALE_VOICES[language]?.test(voice.name),
            ) ??
            voices.find((voice) => voice.default) ??
            voices[0];
          if (preferredVoice) utterance.voice = preferredVoice;
          utterance.addEventListener("error", ({ error }) => {
            if (error !== "canceled" && error !== "interrupted") {
              $speechStatus.textContent =
                "Die Sprachausgabe ist in diesem Browser nicht verfügbar.";
            }
          });
          speech.speak(utterance);
        });
      }
    }
    if (!this.$form) {
      this.$actions.hidden = false;
      return;
    }

    this.$answer = this.$form.elements.namedItem("answer");
    this.$record = this.$root.querySelector("[data-record]");
    this.$submit = this.$form.querySelector('button[type="submit"]');
    this.$next = this.$root.querySelector("[data-next-card]");
    this.$nextLink = this.$next.querySelector("a");
    let recording;
    this.$nextLink.addEventListener("click", (event) => {
      if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.altKey
      ) {
        return;
      }
      event.preventDefault();
      this.loadNext(this.$nextLink.href, this.continueRecording);
    });

    this.$form.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!this.$submit.disabled) {
        unlockRatingSound();
        this.grade(new FormData(this.$form));
      }
    });
    this.canRecord = Boolean(
      navigator.mediaDevices?.getUserMedia &&
      window.MediaRecorder &&
      window.AudioContext &&
      window.OfflineAudioContext,
    );
    this.$record.disabled = !this.canRecord;

    this.$record.addEventListener("click", async () => {
      unlockRatingSound();
      if (recording?.state === "recording") {
        this.$record.disabled = true;
        recording.stop();
        return;
      }
      let stream;
      let context;
      let discarded = false;
      this.cancelCapture = () => {
        discarded = true;
        if (recording?.state === "recording") recording.stop();
        for (let track of stream?.getTracks() ?? []) track.stop();
      };
      this.setBusy(true);
      this.$feedback.hidden = true;
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: { channelCount: 1 },
        });
        if (discarded) {
          this.cancelCapture();
          this.cancelCapture = undefined;
          this.setBusy(false);
          return;
        }
        context = new AudioContext();
        recording = new MediaRecorder(stream);
        let chunks = [];
        recording.addEventListener("dataavailable", (event) =>
          chunks.push(event.data),
        );
        recording.addEventListener("stop", async () => {
          window.clearTimeout(timer);
          for (let track of stream.getTracks()) {
            track.stop();
          }
          setActionLabel(this.$record, "Weiter", "🎙️");
          this.$record.disabled = true;
          let payload;
          try {
            if (discarded) {
              this.$recordStatus.textContent = "";
              this.setBusy(false);
              return;
            }
            let input = await new Blob(chunks, {
              type: recording.mimeType,
            }).arrayBuffer();
            let audio = await context.decodeAudioData(input);
            let sampleRate = 16000;
            let offline = new OfflineAudioContext(
              1,
              Math.min(20 * sampleRate, Math.ceil(audio.duration * sampleRate)),
              sampleRate,
            );
            let source = offline.createBufferSource();
            source.buffer = audio;
            source.connect(offline.destination);
            source.start();
            let samples = (await offline.startRendering()).getChannelData(0);
            let wav = new DataView(new ArrayBuffer(44 + samples.length * 2));
            for (let [offset, value] of [
              [0, "RIFF"],
              [8, "WAVE"],
              [12, "fmt "],
              [36, "data"],
            ]) {
              for (let index = 0; index < value.length; index++) {
                wav.setUint8(offset + index, value.charCodeAt(index));
              }
            }
            for (let [offset, value] of [
              [4, wav.byteLength - 8],
              [16, 16],
              [24, sampleRate],
              [28, sampleRate * 2],
              [40, samples.length * 2],
            ]) {
              wav.setUint32(offset, value, true);
            }
            for (let [offset, value] of [
              [20, 1],
              [22, 1],
              [32, 2],
              [34, 16],
            ]) {
              wav.setUint16(offset, value, true);
            }
            samples.forEach((sample, index) => {
              wav.setInt16(
                44 + index * 2,
                Math.max(-1, Math.min(1, sample)) * 32767,
                true,
              );
            });
            let audioFile = new File([wav.buffer], "antwort.wav", {
              type: "audio/wav",
            });
            payload = new FormData(this.$form);
            payload.set("answer", "");
            payload.set("voice", audioFile);
            this.$recordStatus.textContent = "";
          } catch {
            this.$recordStatus.textContent =
              "Die Aufnahme konnte nicht verarbeitet werden.";
            this.setBusy(false);
          } finally {
            await context.close();
            this.cancelCapture = undefined;
          }
          if (payload && !discarded) {
            this.grade(payload);
          }
        });
        recording.start();
        let timer = window.setTimeout(() => {
          if (recording.state === "recording") {
            recording.stop();
          }
        }, 20000);
        setActionLabel(this.$record, "Aufnahme stoppen", "⏹️");
        this.$recordStatus.textContent =
          "Aufnahme läuft (maximal 20 Sekunden).";
        this.$record.disabled = false;
      } catch {
        this.cancelCapture = undefined;
        for (let track of stream?.getTracks() ?? []) {
          track.stop();
        }
        if (context) {
          await context.close();
        }
        this.$recordStatus.textContent =
          "Kein Mikrofonzugriff. Wähle im Menü einen anderen Antwortmodus.";
        this.setBusy(false);
      }
    });
    this.applyMode();
    $answerMode.addEventListener("change", () => this.applyMode(), { signal });
    this.$actions.hidden = false;
    if (startRecording && this.canRecord && !this.$record.hidden) {
      this.$record.click();
    }
  }

  applyMode() {
    if (!this.$solution.hidden) return;
    let mode = $answerMode.value;
    this.$reveal.hidden = mode !== "reveal";
    this.$record.hidden = mode !== "speak";
    this.$form.hidden = mode !== "text";
    this.$feedback.hidden = true;
    this.$recordStatus.textContent =
      mode === "speak" && !this.canRecord
        ? "Aufnahmen sind in diesem Browser nicht verfügbar."
        : "";
  }

  setBusy(busy) {
    this.$reveal.disabled = busy;
    if (this.$hint) {
      this.$hint.disabled = busy || this.hintUsed;
    }
    if (!this.$form) return;
    this.$record.disabled = busy || !this.canRecord;
    $answerMode.disabled = busy;
    this.$submit.disabled = busy;
    this.$answer.readOnly = busy;
  }

  async saveRating($rating) {
    unlockRatingSound();
    let payload = new FormData(this.$ratings);
    payload.set("rating", $rating.value);
    let $buttons = this.$ratings.querySelectorAll("button:not(:disabled)");
    for (let $button of $buttons) $button.disabled = true;
    this.$feedback.hidden = true;
    let result;
    try {
      let response = await fetch(this.$ratings.action, {
        method: "POST",
        headers: { Accept: "application/json" },
        body: payload,
      });
      result = await response.json();
      if (!response.ok) throw new Error(result.error);
    } catch (error) {
      this.$feedback.classList.add("error");
      this.$feedback.textContent =
        error instanceof SyntaxError || error instanceof TypeError
          ? "Die Bewertung konnte nicht bestätigt werden. Bitte lade die Karte neu."
          : error.message;
      this.$feedback.hidden = false;
      for (let $button of $buttons) $button.disabled = false;
      return;
    }
    window.requestAnimationFrame(() => playRating(result.rating));
    await this.loadNext(this.$ratings.action);
  }

  async grade(payload) {
    this.setBusy(true);
    this.$feedback.hidden = false;
    this.$feedback.classList.remove("error");
    this.$feedback.textContent = "Die Antwort wird geprüft …";
    try {
      let response = await fetch(window.location.href, {
        method: "POST",
        body: payload,
      });
      let result = await response.json();
      if (!response.ok) {
        throw new Error(
          result.error || "Die Antwort konnte nicht geprüft werden.",
        );
      }
      let transcript = result.transcript
        ? `Erkannt: „${result.transcript}“. `
        : "";
      this.$feedback.textContent = `Bewertung gespeichert: ${result.ratingLabel}. ${transcript}${result.feedback}`;
      if (Number.isInteger(result.remaining)) {
        let $remaining = document.querySelector("[data-study-remaining]");
        $remaining.textContent = result.remaining;
        $remaining.parentElement.dataset.remaining = result.remaining;
        if (result.remaining === 0) {
          setActionLabel(this.$nextLink, "Fertig", "🏁");
        }
      }
      this.continueRecording = payload.has("voice") && result.remaining !== 0;
      this.reveal();
      this.$next.hidden = false;
      window.requestAnimationFrame(() => playRating(result.rating));
    } catch (error) {
      this.$feedback.classList.add("error");
      this.$feedback.textContent =
        error instanceof SyntaxError
          ? "Die Antwort konnte nicht geprüft werden."
          : error.message;
    } finally {
      this.setBusy(false);
    }
  }

  async loadNext(url, startRecording = false) {
    if (this.loadingNext) return;
    this.loadingNext = true;
    let $workspace = this.$root.closest("main");
    $workspace.setAttribute("aria-busy", "true");
    let nextPage;
    let $nextWorkspace;
    try {
      let response = await fetch(url, { cache: "no-store" });
      if (!response.ok || response.redirected) throw new Error();
      nextPage = new DOMParser().parseFromString(
        await response.text(),
        "text/html",
      );
      $nextWorkspace = nextPage.querySelector("main.workspace");
      let nextCsrf = nextPage.querySelector(
        '[data-sync-shortcut] [name="csrf"]',
      )?.value;
      let nextMode = nextPage.querySelector("[data-answer-mode]");
      if (
        !$nextWorkspace ||
        nextCsrf !== $syncShortcut.elements.namedItem("csrf").value ||
        nextMode?.dataset.answerMode !== $answerMode?.dataset.answerMode
      ) {
        throw new Error();
      }
    } catch {
      if (startRecording) {
        try {
          sessionStorage.setItem(Study.NEXT_AUDIO_KEY, url);
        } catch {
          // A full navigation still works without recording continuity.
        }
      }
      window.location.assign(url);
      return;
    }
    this.events.abort();
    $workspace.className = $nextWorkspace.className;
    $workspace.replaceChildren(...$nextWorkspace.childNodes);
    document.title = nextPage.title;
    if (window.location.href !== url) {
      window.history.replaceState(window.history.state, "", url);
    }
    mountWorkspace($workspace, startRecording);
    $workspace.removeAttribute("aria-busy");
    window.scrollTo(0, 0);
    if (!$workspace.inert) $workspace.focus({ preventScroll: true });
  }

  reveal() {
    this.$solution.hidden = false;
    if (this.$mnemonic) {
      this.$mnemonic.querySelector("[data-mnemonic-details]").hidden = false;
      this.$mnemonic.hidden = false;
    }
    this.$reveal.setAttribute("aria-expanded", "true");
    this.$actions.hidden = true;
    if (this.$hint) {
      this.$hint.hidden = true;
    }
    this.$solution.focus();
  }
}

class MediaStatus {
  static SELECTOR = "[data-media-status]";
  static CHECK_INTERVAL = 5000;

  constructor($root) {
    this.$root = $root;
  }

  ready() {
    let $stage = this.$root.querySelector("[data-media-stage]");
    let $progress = this.$root.querySelector("[data-mnemonic-progress]");
    let $problems = this.$root.querySelector("[data-mnemonic-problems]");
    let $text = this.$root.querySelector("[data-mnemonic-text]");
    let $image = this.$root.querySelector("[data-mnemonic-image]");
    let $retry = this.$root.querySelector("[data-media-retry]");
    let check = async () => {
      if (!this.$root.isConnected) return;
      if (!document.hidden) {
        try {
          let response = await fetch(this.$root.dataset.mediaStatus, {
            cache: "no-store",
          });
          if (
            !this.$root.isConnected ||
            [401, 403, 404].includes(response.status)
          ) {
            return;
          }
          if (response.ok) {
            let result = await response.json();
            if (
              this.$root.hasAttribute("data-watch-video") &&
              !result.pending
            ) {
              window.location.reload();
              return;
            }
            if ($stage && $stage.textContent !== result.label) {
              $stage.textContent = result.label;
            }
            if ($progress && result.images) {
              let { total, texts, ready, pending, failed, unknown } =
                result.images;
              let progress = `${texts} von ${total} Merksätze · ${ready} von ${total} Merkbilder`;
              if ($progress.textContent !== progress) {
                $progress.textContent = progress;
              }
              let problems =
                (pending ? `${pending} ausstehend. ` : "") +
                (failed ? `${failed} fehlgeschlagen. ` : "") +
                (unknown ? `${unknown} Bildaufträge mit unklarem Status.` : "");
              if ($problems.textContent !== problems)
                $problems.textContent = problems;
            }
            if ($text && result.text && $text.textContent !== result.text) {
              $text.textContent = result.text;
            }
            if ($image && result.url) {
              $image.src = result.url;
              $image.hidden = false;
              if ($stage && !result.label) $stage.hidden = true;
            }
            if ($retry) {
              $retry.hidden = !result.retryImage;
            }
            if (!result.pending && !result.images?.pending) {
              return;
            }
          }
        } catch {
          // A temporarily busy collection should not interrupt a review.
        }
      }
      window.setTimeout(check, MediaStatus.CHECK_INTERVAL);
    };
    window.setTimeout(check, MediaStatus.CHECK_INTERVAL);
  }
}

class Editor {
  static SELECTOR = "[data-editor]";

  constructor($form) {
    this.$form = $form;
  }

  ready() {
    let $deck = this.$form.elements.namedItem("deck");
    let $title = this.$form.elements.namedItem("title");
    let $type = this.$form.elements.namedItem("type");
    let $documents = this.$form.elements.namedItem("document[]");
    let refresh = () => {
      $title.disabled = $deck.value !== "0";
      $title.required = !$title.disabled;
      $documents.required = $type.value === "test";
    };
    $deck.addEventListener("change", () => {
      let vocabulary =
        $deck.value === "0" ||
        $deck.selectedOptions[0]?.dataset.vocabulary === "yes";
      if (vocabulary || $type.value === "vocabulary") {
        $type.value = vocabulary ? "vocabulary" : "topic";
      }
    });
    this.$form.addEventListener("change", refresh);
    refresh();
    this.$form.addEventListener("submit", () => {
      let $button = this.$form.querySelector('button[type="submit"]');
      $button.disabled = true;
      setActionLabel($button, "Vorschau wird erstellt …", "⏳");
    });
  }
}

let $syncForm = document.querySelector("[data-sync]");
$syncForm?.addEventListener("submit", () => {
  let $submit = $syncForm.querySelector('button[type="submit"]');
  $submit.disabled = true;
  setActionLabel($submit, "Synchronisierung läuft …", "🔄");
});

async function syncNow($form, $feedback) {
  let $submit = $form.querySelector('button[type="submit"]');
  if ($submit.disabled) return;
  $submit.disabled = true;
  $form.dataset.state = "pending";
  $feedback.textContent = "Synchronisierung läuft …";
  let state = "error";
  let message = "Synchronisierung fehlgeschlagen.";
  try {
    let response = await fetch($form.action, {
      method: "POST",
      headers: { Accept: "application/json" },
      body: new FormData($form),
      keepalive: true,
    });
    let result = await response.json();
    if (response.ok && result.synced === true) {
      state = "success";
      message = "Synchronisiert.";
    }
    if (!response.ok && result.error) message = result.error;
  } catch {
    message = "Kein Sync-Ergebnis erhalten.";
  }
  $form.dataset.state = state;
  $feedback.dataset.state = state;
  $feedback.textContent = message;
  $submit.disabled = false;
}

let $syncShortcut = document.querySelector("[data-sync-shortcut]");
$syncShortcut?.addEventListener("submit", (event) => {
  event.preventDefault();
  syncNow($syncShortcut, $syncShortcut.querySelector('[role="status"]'));
});
function mountWorkspace($scope, startRecording) {
  let $study = $scope.querySelector(Study.SELECTOR);
  if ($study) new Study($study).ready(startRecording);
  for (let $element of $scope.querySelectorAll(MediaStatus.SELECTOR)) {
    new MediaStatus($element).ready();
  }
  let $completionSync = $scope.querySelector("[data-completion-sync]");
  if ($syncShortcut && $completionSync) syncNow($syncShortcut, $completionSync);
}

let $menu = document.querySelector("[data-mobile-menu]");
let $answerMode = document.querySelector("[data-answer-mode]");
if ($menu) {
  let $backdrop = document.querySelector("[data-mobile-menu-backdrop]");
  let $main = document.getElementById("main");
  let $skip = document.querySelector(".skip");
  let mobile = window.matchMedia("(max-width: 700px)");
  let isExpanded = () => $menu.getAttribute("aria-expanded") === "true";
  let setExpanded = (expanded) => {
    $menu.setAttribute("aria-expanded", String(expanded));
    $main.inert = expanded;
    $skip.inert = expanded;
    if (!expanded && mobile.matches) $menu.focus();
  };
  $menu.addEventListener("click", () => setExpanded(!isExpanded()));
  $backdrop.addEventListener("click", () => setExpanded(false));
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !event.defaultPrevented && isExpanded()) {
      setExpanded(false);
    }
  });
  mobile.addEventListener("change", () => {
    if (!mobile.matches && isExpanded()) setExpanded(false);
  });
  $answerMode?.addEventListener("change", () => {
    if (isExpanded()) setExpanded(false);
  });
}

if ($answerMode) {
  let key = `bruno-answer-mode:${$answerMode.dataset.answerMode}`;
  try {
    $answerMode.value = localStorage.getItem(key) || "reveal";
  } catch {
    $answerMode.value = "reveal";
  }
  if (!$answerMode.value) $answerMode.value = "reveal";
  $answerMode.addEventListener("change", () => {
    try {
      localStorage.setItem(key, $answerMode.value);
    } catch {
      return;
    }
  });
  $answerMode.closest("label").hidden = false;
}

let nextAudioPage;
try {
  nextAudioPage = sessionStorage.getItem(Study.NEXT_AUDIO_KEY);
  sessionStorage.removeItem(Study.NEXT_AUDIO_KEY);
} catch {
  nextAudioPage = null;
}

mountWorkspace(document, nextAudioPage === window.location.href);
for (let $element of document.querySelectorAll(Editor.SELECTOR)) {
  new Editor($element).ready();
}

let $ratedReview = document.querySelector("[data-review-rating]");
if ($ratedReview) celebrateReview(Number($ratedReview.dataset.reviewRating));

document.querySelector("[data-generate-batch]")?.requestSubmit();

let installPrompt;
window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  installPrompt = event;
});
document.querySelectorAll("[data-install]").forEach(($button) => {
  if (
    window.matchMedia("(display-mode: standalone)").matches ||
    navigator.standalone
  ) {
    $button.hidden = true;
    return;
  }
  $button.addEventListener("click", async () => {
    if (installPrompt) {
      await installPrompt.prompt();
      installPrompt = undefined;
      return;
    }
    let $help = document.getElementById($button.getAttribute("aria-controls"));
    $help.hidden = !$help.hidden;
    $button.setAttribute("aria-expanded", String(!$help.hidden));
  });
});
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("/sw.js").catch(() => {});
}
