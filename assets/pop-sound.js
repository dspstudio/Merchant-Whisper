/**
 * Pure sine-wave pop for Merchant Whisper (Web Audio API).
 */
(function (window) {
  'use strict';

  var ctx = null;

  function getContext() {
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) {
      return null;
    }
    if (!ctx) {
      ctx = new Ctx();
    }
    return ctx;
  }

  function trigger(audio) {
    var now = audio.currentTime;
    var master = audio.createGain();
    master.gain.setValueAtTime(0.85, now);
    master.connect(audio.destination);

    var oscA = audio.createOscillator();
    var gainA = audio.createGain();
    oscA.type = 'sine';
    oscA.frequency.setValueAtTime(1200, now);
    oscA.frequency.exponentialRampToValueAtTime(420, now + 0.07);
    gainA.gain.setValueAtTime(0.0001, now);
    gainA.gain.exponentialRampToValueAtTime(0.28, now + 0.006);
    gainA.gain.exponentialRampToValueAtTime(0.0001, now + 0.11);
    oscA.connect(gainA);
    gainA.connect(master);
    oscA.start(now);
    oscA.stop(now + 0.12);

    var oscB = audio.createOscillator();
    var gainB = audio.createGain();
    oscB.type = 'sine';
    oscB.frequency.setValueAtTime(520, now);
    oscB.frequency.exponentialRampToValueAtTime(160, now + 0.1);
    gainB.gain.setValueAtTime(0.0001, now);
    gainB.gain.exponentialRampToValueAtTime(0.16, now + 0.01);
    gainB.gain.exponentialRampToValueAtTime(0.0001, now + 0.14);
    oscB.connect(gainB);
    gainB.connect(master);
    oscB.start(now);
    oscB.stop(now + 0.15);
  }

  function playPop() {
    var audio = getContext();
    if (!audio) {
      return;
    }

    if (audio.state === 'suspended') {
      audio
        .resume()
        .then(function () {
          trigger(audio);
        })
        .catch(function () {
          /* ignore */
        });
      return;
    }

    trigger(audio);
  }

  window.mwSalesToastPlayPop = playPop;
})(window);
