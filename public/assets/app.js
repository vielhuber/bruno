let launchConfetti;

function celebrateReview() {
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
  let colors = ["#c4b3ff", "#a9ebce", "#ffcf7d", "#ff9fbf", "#80ddff"];
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

class Study {
  static SELECTOR = "[data-study]";
  static NEXT_AUDIO_KEY = "bruno-next-audio";

  constructor($root) {
    this.$root = $root;
  }

  ready() {
    this.$solution = this.$root.querySelector("#solution");
    this.$actions = this.$root.querySelector("[data-answer-actions]");
    this.$reveal = this.$root.querySelector("[data-reveal]");
    this.$ratings = this.$root.querySelector("[data-ratings]");
    this.$form = this.$root.querySelector("[data-grade]");
    this.$feedback = this.$root.querySelector("[data-feedback]");
    this.$hint = this.$root.querySelector("[data-study-hint]");
    this.hintUsed = this.$hint?.disabled ?? false;
    let $recordStatus = this.$root.querySelector("[data-record-status]");
    this.$reveal.addEventListener("click", () => {
      this.reveal();
      this.$ratings.hidden = false;
      if (this.$feedback) {
        this.$feedback.hidden = true;
        $recordStatus.textContent = "";
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
        $status.textContent = `Tipp: ${result.letter} · Höchstens „Gut“ möglich.`;
      } catch {
        $status.textContent =
          "Der Tipp konnte nicht angezeigt werden. Bitte erneut versuchen.";
      } finally {
        this.setBusy(false);
      }
    });
    let $speak = this.$root.querySelector("[data-speak]");
    let speech = window.speechSynthesis;
    if ($speak && speech && typeof SpeechSynthesisUtterance === "function") {
      let $speechText = this.$root.querySelector("[data-speech-text]");
      let $speechStatus = this.$root.querySelector("[data-speech-status]");
      $speak.hidden = false;
      $speak.addEventListener("click", () => {
        $speechStatus.textContent = "";
        speech.cancel();
        let utterance = new SpeechSynthesisUtterance($speechText.textContent);
        let language = $speak.dataset.speak;
        if (language) {
          utterance.lang = language;
          let localVoice = speech
            .getVoices()
            .find(
              (voice) =>
                voice.localService &&
                voice.lang.toLowerCase().split("-")[0] === language,
            );
          if (localVoice) utterance.voice = localVoice;
        }
        utterance.addEventListener("error", ({ error }) => {
          if (error !== "canceled" && error !== "interrupted") {
            $speechStatus.textContent =
              "Die Sprachausgabe ist in diesem Browser nicht verfügbar.";
          }
        });
        speech.speak(utterance);
      });
      window.addEventListener("pagehide", () => speech.cancel());
    }
    if (!this.$form) {
      return;
    }

    this.$answer = this.$form.elements.namedItem("answer");
    this.$textAnswer = this.$root.querySelector("[data-text-answer]");
    this.$record = this.$root.querySelector("[data-record]");
    let $recordLabel = this.$record.querySelector("[data-record-label]");
    this.$submit = this.$form.querySelector('button[type="submit"]');
    this.$next = this.$root.querySelector("[data-next-card]");
    let recording;
    let cancelCapture;
    window.addEventListener("pagehide", () => cancelCapture?.());

    this.$textAnswer.addEventListener("click", () => {
      this.$form.hidden = false;
      this.$textAnswer.setAttribute("aria-expanded", "true");
      this.$answer.focus();
    });
    this.$form.addEventListener("submit", (event) => {
      event.preventDefault();
      if (!this.$submit.disabled) {
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
    if (!this.canRecord) {
      $recordStatus.textContent =
        "Aufnahmen sind in diesem Browser nicht verfügbar.";
    }

    this.$record.addEventListener("click", async () => {
      if (recording?.state === "recording") {
        this.$record.disabled = true;
        recording.stop();
        return;
      }
      let stream;
      let context;
      let discarded = false;
      cancelCapture = () => {
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
          cancelCapture();
          cancelCapture = undefined;
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
          $recordLabel.textContent = "Audioaufnahme";
          this.$record.disabled = true;
          let payload;
          try {
            if (discarded) {
              $recordStatus.textContent = "";
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
            $recordStatus.textContent = "";
          } catch {
            $recordStatus.textContent =
              "Die Aufnahme konnte nicht verarbeitet werden.";
            this.setBusy(false);
          } finally {
            await context.close();
            cancelCapture = undefined;
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
        $recordLabel.textContent = "Aufnahme stoppen";
        $recordStatus.textContent = "Aufnahme läuft (maximal 20 Sekunden).";
        this.$record.disabled = false;
      } catch {
        cancelCapture = undefined;
        for (let track of stream?.getTracks() ?? []) {
          track.stop();
        }
        if (context) {
          await context.close();
        }
        $recordStatus.textContent =
          "Kein Mikrofonzugriff. Du kannst die Antwort schreiben.";
        this.setBusy(false);
      }
    });
    if (this.canRecord && nextAudioPage === window.location.href) {
      this.$record.click();
    }
  }

  setBusy(busy) {
    this.$reveal.disabled = busy;
    if (this.$hint) {
      this.$hint.disabled = busy || this.hintUsed;
    }
    if (!this.$form) return;
    this.$record.disabled = busy || !this.canRecord;
    this.$textAnswer.disabled = busy;
    this.$submit.disabled = busy;
    this.$answer.readOnly = busy;
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
      let $nextCard = this.$next.querySelector("a");
      if (Number.isInteger(result.remaining)) {
        let $remaining = document.querySelector("[data-study-remaining]");
        $remaining.textContent = result.remaining;
        $remaining.parentElement.dataset.remaining = result.remaining;
        if (result.remaining === 0) {
          $nextCard.textContent = "Fertig";
        }
        if (result.rating === 4) celebrateReview();
      }
      if (payload.has("voice") && result.remaining !== 0) {
        $nextCard.addEventListener("click", (event) => {
          if (
            event.defaultPrevented ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.altKey
          ) {
            return;
          }
          try {
            sessionStorage.setItem(Study.NEXT_AUDIO_KEY, $nextCard.href);
          } catch {
            // Browsers that block storage can still record manually after navigation.
          }
        });
      }
      this.reveal();
      this.$next.hidden = false;
    } catch (error) {
      this.$feedback.classList.add("error");
      this.$feedback.textContent =
        error instanceof SyntaxError
          ? "Die Antwort konnte nicht geprüft werden."
          : error.message;
      this.setBusy(false);
    }
  }

  reveal() {
    this.$solution.hidden = false;
    this.$reveal.setAttribute("aria-expanded", "true");
    this.$actions.hidden = true;
    if (this.$form) {
      this.$form.hidden = true;
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
    let $progress = this.$root.querySelector("[data-image-progress]");
    let $image = this.$root.querySelector("[data-mnemonic-image]");
    let $retry = this.$root.querySelector("[data-media-retry]");
    let check = async () => {
      if (!document.hidden) {
        try {
          let response = await fetch(this.$root.dataset.mediaStatus, {
            cache: "no-store",
          });
          if ([401, 403, 404].includes(response.status)) {
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
            if ($progress) {
              let progress = `${result.images.ready} von ${result.images.total} fertig.`;
              if ($progress.textContent !== progress) {
                $progress.textContent = progress;
              }
            }
            if ($image && result.url) {
              $image.src = result.url;
              $image.hidden = false;
              $stage.parentElement.hidden = true;
            }
            if ($retry) {
              $retry.hidden = result.state !== "failed";
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
      $button.textContent = "Vorschau wird erstellt …";
    });
  }
}

let $syncForm = document.querySelector("[data-sync]");
$syncForm?.addEventListener("submit", () => {
  let $submit = $syncForm.querySelector('button[type="submit"]');
  $submit.disabled = true;
  $submit.textContent = "Synchronisierung läuft …";
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
let $completionSync = document.querySelector("[data-completion-sync]");
if ($syncShortcut && $completionSync) syncNow($syncShortcut, $completionSync);

let nextAudioPage;
try {
  nextAudioPage = sessionStorage.getItem(Study.NEXT_AUDIO_KEY);
  sessionStorage.removeItem(Study.NEXT_AUDIO_KEY);
} catch {
  nextAudioPage = null;
}

for (let Module of [Study, Editor, MediaStatus]) {
  for (let $element of document.querySelectorAll(Module.SELECTOR)) {
    new Module($element).ready();
  }
}

if (document.querySelector("[data-celebrate]")) celebrateReview();

let $menu = document.querySelector("[data-mobile-menu]");
$menu?.addEventListener("click", () => {
  $menu.setAttribute(
    "aria-expanded",
    String($menu.getAttribute("aria-expanded") !== "true"),
  );
});

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
