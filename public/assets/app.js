class Study {
  static SELECTOR = "[data-study]";

  constructor($root) {
    this.$root = $root;
  }

  ready() {
    this.$solution = this.$root.querySelector("#solution");
    this.$reveal = this.$root.querySelector("[data-reveal]");
    this.$ratings = this.$root.querySelector("[data-ratings]");
    this.$reveal.addEventListener("click", () => this.reveal());
    let $form = this.$root.querySelector("[data-grade]");
    if (!$form) {
      return;
    }

    let $answer = $form.elements.namedItem("answer");
    let $feedback = this.$root.querySelector("[data-feedback]");
    let $record = $form.querySelector("[data-record]");
    let $submit = $form.querySelector('button[type="submit"]');
    let $recordStatus = $form.querySelector("[data-record-status]");
    let recording;
    let audioFile;

    $answer.addEventListener("input", () => {
      audioFile = undefined;
      $recordStatus.textContent = "";
    });
    $record.disabled =
      !navigator.mediaDevices?.getUserMedia ||
      !window.MediaRecorder ||
      !window.AudioContext;
    if ($record.disabled) {
      $recordStatus.textContent =
        "Aufnahmen sind in diesem Browser nicht verfügbar.";
    }

    $record.addEventListener("click", async () => {
      if (recording?.state === "recording") {
        recording.stop();
        return;
      }
      let stream;
      let context;
      $record.disabled = true;
      $submit.disabled = true;
      audioFile = undefined;
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          audio: { channelCount: 1 },
        });
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
          $record.textContent = "Aufnehmen";
          $record.disabled = true;
          try {
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
            audioFile = new File([wav.buffer], "antwort.wav", {
              type: "audio/wav",
            });
            $answer.value = "";
            $recordStatus.textContent =
              "Aufnahme bereit. Mit „Mit KI prüfen“ absenden.";
          } catch {
            $recordStatus.textContent =
              "Die Aufnahme konnte nicht verarbeitet werden.";
          } finally {
            await context.close();
            $record.disabled = false;
            $submit.disabled = false;
          }
        });
        recording.start();
        let timer = window.setTimeout(() => {
          if (recording.state === "recording") {
            recording.stop();
          }
        }, 20000);
        $record.textContent = "Aufnahme stoppen";
        $recordStatus.textContent = "Aufnahme läuft (maximal 20 Sekunden).";
        $record.disabled = false;
      } catch {
        for (let track of stream?.getTracks() ?? []) {
          track.stop();
        }
        if (context) {
          await context.close();
        }
        $recordStatus.textContent =
          "Kein Mikrofonzugriff. Du kannst die Antwort schreiben.";
        $record.disabled = false;
        $submit.disabled = false;
      }
    });

    $form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if ($submit.disabled) {
        return;
      }
      let payload = new FormData($form);
      if (audioFile) {
        payload.set("voice", audioFile);
      }
      $submit.disabled = true;
      $feedback.hidden = false;
      $feedback.classList.remove("error");
      $feedback.textContent = "Die Antwort wird geprüft …";
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
        this.reveal();
        let transcript = result.transcript
          ? `Erkannt: „${result.transcript}“. `
          : "";
        $feedback.textContent = `${transcript}${result.feedback} Du entscheidest, wie du die Karte bewertest.`;
        this.$ratings.querySelectorAll("button").forEach(($rating) => {
          $rating.classList.toggle(
            "suggested",
            Number($rating.value) === result.suggested,
          );
        });
      } catch (error) {
        $feedback.classList.add("error");
        $feedback.textContent =
          error instanceof SyntaxError
            ? "Die Antwort konnte nicht geprüft werden."
            : error.message;
      } finally {
        $submit.disabled = false;
      }
    });
  }

  reveal() {
    this.$solution.hidden = false;
    this.$reveal.setAttribute("aria-expanded", "true");
    this.$reveal.hidden = true;
    this.$ratings.hidden = false;
    this.$solution.focus();
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
      let vocabulary = $deck.selectedOptions[0]?.dataset.vocabulary === "yes";
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

for (let Module of [Study, Editor]) {
  for (let $element of document.querySelectorAll(Module.SELECTOR)) {
    new Module($element).ready();
  }
}

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
