let launchConfetti;
let ratingAudio;

function celebrationCanvas() {
  if (
    !window.confetti ||
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  ) {
    return;
  }
  launchConfetti ??= window.confetti.create(null, {
    resize: true,
    useWorker: false,
    disableForReducedMotion: true,
  });
  return launchConfetti;
}

function celebrateReview(rating) {
  if (rating <= 2 && !window.OffscreenCanvas?.prototype.transferToImageBitmap) {
    return;
  }
  let burst = celebrationCanvas();
  if (!burst) return;
  if (rating <= 2) {
    let retry = rating === 1;
    let scalar = 1.8;
    return burst({
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
  }
  let colors = ["#c4b3ff", "#a9ebce", "#ffcf7d", "#ff9fbf", "#80ddff"];
  if (rating === 3) {
    return burst({
      particleCount: 24,
      spread: 58,
      startVelocity: 30,
      ticks: 90,
      origin: { x: 0.5, y: 0.68 },
      colors,
    });
  }
  for (let [x, angle] of [
    [0.05, 65],
    [0.95, 115],
  ]) {
    burst({
      particleCount: 135,
      angle,
      spread: 68,
      startVelocity: 57,
      ticks: 160,
      origin: { x, y: 0.7 },
      colors,
    });
  }
  return burst({
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

function celebrateCompletion($completion) {
  $completion.removeAttribute("data-celebrate-completion");
  window.requestAnimationFrame(() => {
    if (!$completion.isConnected) return;
    playNotes(
      [392, 523, 659, 784, 1047, 1319, 1568, 2093],
      "triangle",
      0.105,
      0.31,
    );
    let burst = celebrationCanvas();
    if (!burst) return;
    let compact = window.matchMedia("(max-width: 700px)").matches;
    let colors = [
      "#ffd166",
      "#ff78a8",
      "#9e81ff",
      "#75e6ff",
      "#a9ebce",
      "#fff5c8",
    ];
    for (let [x, angle] of [
      [0.04, 68],
      [0.96, 112],
      [0.5, 90],
    ]) {
      burst({
        angle,
        particleCount: compact ? 130 : 185,
        spread: 103,
        startVelocity: 56,
        gravity: 0.8,
        ticks: 170,
        scalar: 1.12,
        origin: { x, y: 0.83 },
        shapes: ["square", "circle", "star"],
        colors,
      });
    }
    let until = performance.now() + 6200;
    let rain = () => {
      if (!$completion.isConnected || performance.now() >= until) return;
      for (let lane = 0; lane < 3; lane++) {
        burst({
          angle: 270,
          particleCount: compact ? 12 : 21,
          spread: 115,
          startVelocity: 21,
          decay: 0.92,
          gravity: 0.95,
          ticks: 170,
          scalar: 0.9 + Math.random() * 0.34,
          origin: { x: (lane + Math.random()) / 3, y: 0 },
          shapes: ["square", "circle", "star"],
          colors,
        });
      }
      window.setTimeout(rain, 165);
    };
    rain();
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

function playRatingSound(rating) {
  let notes = [
    [330, 220, 165],
    [220, 277],
    [523, 659, 784],
    [523, 659, 784, 1047],
  ][rating - 1];
  if (notes) playNotes(notes, rating === 1 ? "sine" : "triangle");
}

function playNotes(notes, shape, spacing = 0.085, duration = 0.18) {
  if (ratingAudio?.state === "running") {
    let start = ratingAudio.currentTime;
    for (let [index, frequency] of notes.entries()) {
      let time = start + index * spacing;
      let oscillator = ratingAudio.createOscillator();
      let gain = ratingAudio.createGain();
      oscillator.type = shape;
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0, time);
      gain.gain.linearRampToValueAtTime(0.095, time + 0.018);
      gain.gain.exponentialRampToValueAtTime(0.001, time + duration - 0.01);
      oscillator.connect(gain).connect(ratingAudio.destination);
      oscillator.start(time);
      oscillator.stop(time + duration);
    }
  }
}

function playRating(rating) {
  let confettiStarted = false;
  try {
    confettiStarted = Boolean(celebrateReview(rating));
  } catch {
    // Keep the tone if the canvas effect cannot start.
  }
  if (confettiStarted) {
    // The renderer queues its draw before this sound callback.
    window.requestAnimationFrame(() => playRatingSound(rating));
    return;
  }
  playRatingSound(rating);
}

function setActionLabel($action, label, emoji) {
  $action.querySelector(".action-label").textContent = label;
  for (let $decoration of $action.querySelectorAll(".action-emoji")) {
    $decoration.textContent = emoji;
  }
}

class Study {
  static SELECTOR = "[data-study]";
  static MALE_VOICES = {
    de: /\b(?:markus|yannick|martin|stefan|conrad|michael|karsten)\b/i,
    en: /\b(?:alex|daniel|tom|david|mark|guy|george|ryan|james|richard|sean|ravi)\b/i,
    es: /\b(?:pablo|jorge|álvaro|alvaro|raul|diego)\b/i,
    fr: /\b(?:thomas|henri|paul|daniel|claude|guillaume)\b/i,
    it: /\b(?:cosimo|luca)\b/i,
  };

  constructor($root, $answerMode, signal) {
    this.$root = $root;
    this.$answerMode = $answerMode;
    this.signal = signal;
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
    let signal = this.signal;
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
        $status.textContent = result.hint;
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
          let utterance = new SpeechSynthesisUtterance(
            $speechText.dataset.speechText,
          );
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
      unlockRatingSound();
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
    this.$answerMode.addEventListener("change", () => this.applyMode(), {
      signal,
    });
    this.$actions.hidden = false;
    if (startRecording && this.canRecord && !this.$record.hidden) {
      this.$record.click();
    }
  }

  applyMode() {
    if (!this.$solution.hidden) return;
    let mode = this.$answerMode.value;
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
    this.$answerMode.disabled = busy;
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
    if (this.signal.aborted || navigation.request) return;
    playRating(result.rating);
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
      if (this.signal.aborted) return;
      let transcript = result.transcript
        ? `Erkannt: „${result.transcript}“. `
        : "";
      this.$feedback.textContent = `Bewertung gespeichert: ${result.ratingLabel}. ${transcript}${result.feedback}`;
      if (Number.isInteger(result.remaining)) {
        let $remaining = this.$root
          .closest("main")
          .querySelector("[data-study-remaining]");
        $remaining.textContent = result.remaining;
        $remaining.parentElement.dataset.remaining = result.remaining;
        if (result.remaining === 0) {
          setActionLabel(this.$nextLink, "Fertig", "🏁");
        }
      }
      this.continueRecording = payload.has("voice") && result.remaining !== 0;
      this.reveal();
      this.$next.hidden = false;
      playRating(result.rating);
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

  loadNext(url, startRecording = false) {
    return navigation.open(new URL(url), { replace: true, startRecording });
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
              if (navigation.request) {
                window.setTimeout(check, MediaStatus.CHECK_INTERVAL);
                return;
              }
              await navigation.open(new URL(window.location.href), {
                replace: true,
                keepScroll: true,
              });
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
  }
}

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

let installPrompt;
window.addEventListener("beforeinstallprompt", (event) => {
  event.preventDefault();
  installPrompt = event;
});

function mountPage($scope, signal, startRecording) {
  let $syncShortcut = $scope.querySelector("[data-sync-shortcut]");
  $syncShortcut?.addEventListener("submit", (event) => {
    event.preventDefault();
    syncNow($syncShortcut, $syncShortcut.querySelector('[role="status"]'));
  });
  let $answerMode = $scope.querySelector("[data-answer-mode]");
  if ($answerMode) {
    let key = "bruno-answer-mode:" + $answerMode.dataset.answerMode;
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
  let $menu = $scope.querySelector("[data-mobile-menu]");
  if ($menu) {
    let $backdrop = $scope.querySelector("[data-mobile-menu-backdrop]");
    let $main = $scope.querySelector("main");
    let $skip = $scope.querySelector(".skip");
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
    document.addEventListener(
      "keydown",
      (event) => {
        if (event.key === "Escape" && !event.defaultPrevented && isExpanded()) {
          setExpanded(false);
        }
      },
      { signal },
    );
    mobile.addEventListener(
      "change",
      () => {
        if (!mobile.matches && isExpanded()) setExpanded(false);
      },
      { signal },
    );
    $answerMode?.addEventListener("change", () => {
      if (isExpanded()) setExpanded(false);
    });
  }

  let $install = $scope.querySelector("[data-install]");
  if ($install) {
    $install.hidden =
      window.matchMedia("(display-mode: standalone)").matches ||
      navigator.standalone;
    $install.addEventListener("click", async () => {
      if (installPrompt) {
        await installPrompt.prompt();
        installPrompt = undefined;
        return;
      }
      let $help = $scope.querySelector("#install-help");
      $help.hidden = !$help.hidden;
      $install.setAttribute("aria-expanded", String(!$help.hidden));
    });
  }
  let $study = $scope.querySelector(Study.SELECTOR);
  if ($study) new Study($study, $answerMode, signal).ready(startRecording);
  for (let $element of $scope.querySelectorAll(MediaStatus.SELECTOR)) {
    new MediaStatus($element).ready();
  }
  let $completion = $scope.querySelector("[data-celebrate-completion]");
  if ($completion) celebrateCompletion($completion);
  let $completionSync = $scope.querySelector("[data-completion-sync]");
  if ($syncShortcut && $completionSync) syncNow($syncShortcut, $completionSync);
  for (let $form of $scope.querySelectorAll(Editor.SELECTOR)) {
    new Editor($form).ready();
  }
  let $ratedReview = $scope.querySelector("[data-review-rating]");
  if ($ratedReview) celebrateReview(Number($ratedReview.dataset.reviewRating));
  let $batch = $scope.querySelector("[data-generate-batch]");
  queueMicrotask(() => {
    if ($batch?.isConnected) $batch.requestSubmit();
  });
}

class BackgroundMusic {
  static PLAYER = "[data-background-music]";
  static CONTROL = "[data-music-toggle]";
  static PREFERENCE = "bruno-background-music";

  constructor($audio) {
    this.$audio = $audio;
    this.$audio.volume = 0.5;
    this.enabled = true;
    try {
      this.enabled = localStorage.getItem(BackgroundMusic.PREFERENCE) !== "off";
    } catch {
      // The toggle still works for this session without storage.
    }
  }

  ready() {
    document.addEventListener("click", (event) => {
      let $control = event.target.closest(BackgroundMusic.CONTROL);
      if ($control) {
        this.enabled = !this.enabled;
        try {
          localStorage.setItem(
            BackgroundMusic.PREFERENCE,
            this.enabled ? "on" : "off",
          );
        } catch {
          // Keep the preference in memory.
        }
        this.update($control);
        return;
      }
      if (event.isTrusted) this.play();
    });
    document.addEventListener("keydown", (event) => {
      if (event.isTrusted && !event.target.closest(BackgroundMusic.CONTROL))
        this.play();
    });
  }

  play() {
    if (
      this.enabled &&
      document.body.classList.contains("signed-in") &&
      this.$audio.paused
    ) {
      this.$audio.play().catch(() => {});
    }
  }

  update($control) {
    if ($control) {
      setActionLabel(
        $control,
        this.enabled ? "Musik stoppen" : "Musik starten",
        "♪",
      );
      $control.hidden = false;
    }
    if (!$control || !this.enabled) {
      this.$audio.pause();
      return;
    }
    this.play();
  }
}

class Navigation {
  static SELECTOR = "#app";
  static SCRIPT = "[data-app-script]";
  static ERROR = "[data-navigation-error]";
  static DAILY_DECK = "[data-daily-deck]";
  static DECK_TRANSITION_MS = 1250;

  constructor($root) {
    this.$root = $root;
    this.route = window.location.pathname + window.location.search;
    this.script = document.querySelector(Navigation.SCRIPT).getAttribute("src");
  }

  ready() {
    this.$root.addEventListener("click", (event) => {
      if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
      )
        return;
      let $link = event.target.closest("a[href]");
      if (
        !$link ||
        $link.hasAttribute("download") ||
        ($link.target && $link.target !== "_self") ||
        $link.getAttribute("href").startsWith("#")
      )
        return;
      let url = new URL($link.href);
      if (!this.accepts(url)) return;
      event.preventDefault();
      this.open(url);
    });
    this.$root.addEventListener("submit", (event) => this.submit(event));
    window.addEventListener("popstate", () => {
      if (this.route !== window.location.pathname + window.location.search) {
        this.open(new URL(window.location.href), { replace: true });
      }
    });
    this.mount();
  }

  accepts(url) {
    return (
      url.origin === window.location.origin &&
      ["/", "/index.php"].includes(url.pathname) &&
      !["media", "media-status"].includes(url.searchParams.get("view"))
    );
  }

  async submit(event) {
    let $form = event.target;
    if (event.defaultPrevented || $form.method !== "post") return;
    let $button = event.submitter;
    let url = new URL(
      $button?.hasAttribute("formaction") ? $button.formAction : $form.action,
    );
    if (!this.accepts(url)) return;
    event.preventDefault();
    if (this.request?.post || $button?.disabled) return;
    let body = new FormData($form, $button);
    let progress = $form.matches(Editor.SELECTOR)
      ? ["Vorschau wird erstellt …", "⏳"]
      : $form.matches("[data-sync]")
        ? ["Synchronisierung läuft …", "🔄"]
        : null;
    let previous;
    if ($button) {
      $button.disabled = true;
      if (progress) {
        previous = [
          $button.querySelector(".action-label").textContent,
          $button.querySelector(".action-emoji").textContent,
        ];
        setActionLabel($button, ...progress);
      }
    }
    await this.open(url, { body });
    if ($button?.isConnected) {
      $button.disabled = false;
      if (previous) setActionLabel($button, ...previous);
    }
  }

  mount(startRecording = false) {
    this.pageEvents = new AbortController();
    mountPage(this.$root, this.pageEvents.signal, startRecording);
    backgroundMusic.update(this.$root.querySelector(BackgroundMusic.CONTROL));
  }

  async open(
    url,
    { body, replace = false, startRecording = false, keepScroll = false } = {},
  ) {
    this.request?.controller.abort();
    let controller = new AbortController();
    let request = { controller, post: Boolean(body) };
    this.request = request;
    let $main = this.$root.querySelector("main");
    $main.setAttribute("aria-busy", "true");
    $main.querySelector(Navigation.ERROR)?.remove();
    try {
      let response = await fetch(url, {
        method: body ? "POST" : "GET",
        body,
        cache: "no-store",
        signal: controller.signal,
      });
      let nextUrl = new URL(response.url);
      if (!this.accepts(nextUrl)) throw new Error();
      if (
        !nextUrl.hash &&
        nextUrl.pathname === url.pathname &&
        nextUrl.search === url.search
      ) {
        nextUrl.hash = url.hash;
      }
      let page = new DOMParser().parseFromString(
        await response.text(),
        "text/html",
      );
      let $next = page.querySelector(Navigation.SELECTOR);
      if (!$next) throw new Error();
      if (controller.signal.aborted) return;
      if (
        page.querySelector(Navigation.SCRIPT)?.getAttribute("src") !==
        this.script
      ) {
        window.location.assign(nextUrl);
        return;
      }
      let currentDeck = this.$root.querySelector(Navigation.DAILY_DECK)?.dataset
        .dailyDeck;
      let $nextDeck = $next.querySelector(Navigation.DAILY_DECK);
      if (
        currentDeck &&
        $nextDeck &&
        currentDeck !== $nextDeck.dataset.dailyDeck
      ) {
        let $splash = document.createElement("div");
        $splash.className = "deck-transition";
        $splash.setAttribute("role", "status");
        $splash.textContent = $nextDeck.querySelector("h1").textContent;
        document.body.append($splash);
        this.$root.inert = true;
        await new Promise((resolve) => {
          let timer = window.setTimeout(resolve, Navigation.DECK_TRANSITION_MS);
          controller.signal.addEventListener(
            "abort",
            () => {
              window.clearTimeout(timer);
              resolve();
            },
            { once: true },
          );
        });
        $splash.remove();
        this.$root.inert = false;
        if (controller.signal.aborted) return;
      }
      this.pageEvents.abort();
      this.$root.replaceChildren(...$next.childNodes);
      document.body.className = page.body.className;
      document.title = page.title;
      this.route = nextUrl.pathname + nextUrl.search;
      if (nextUrl.href !== window.location.href) {
        window.history[replace ? "replaceState" : "pushState"](
          null,
          "",
          nextUrl,
        );
      }
      this.mount(startRecording);
      if (!keepScroll) {
        let $anchor = document.getElementById(nextUrl.hash.slice(1));
        (
          $anchor ??
          this.$root.querySelector("[autofocus]") ??
          this.$root.querySelector("main")
        ).focus({ preventScroll: true });
        if ($anchor) $anchor.scrollIntoView();
        if (!$anchor) window.scrollTo(0, 0);
      }
    } catch {
      if (controller.signal.aborted) return;
      let $notice = document.createElement("p");
      $notice.className = "notice error";
      $notice.dataset.navigationError = "";
      $notice.setAttribute("role", "alert");
      $notice.textContent = body
        ? "Der Vorgang konnte nicht bestätigt werden. Bitte lade die Seite neu."
        : "Die Seite konnte nicht geladen werden. Bitte erneut versuchen.";
      this.$root.querySelector("main").prepend($notice);
    } finally {
      if (this.request === request) {
        this.request = undefined;
        $main.removeAttribute("aria-busy");
      }
    }
  }
}

let backgroundMusic = new BackgroundMusic(
  document.querySelector(BackgroundMusic.PLAYER),
);
backgroundMusic.ready();
let navigation = new Navigation(document.querySelector(Navigation.SELECTOR));
navigation.ready();
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("/sw.js").catch(() => {});
}
