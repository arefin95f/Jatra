document.querySelectorAll("form[data-confirm]").forEach(function (form) {
  form.addEventListener("submit", function (event) {
    if (!window.confirm(form.getAttribute("data-confirm"))) {
      event.preventDefault();
    }
  });
});

document.querySelectorAll("[data-otp-countdown]").forEach(function (countdown) {
  var button = document.querySelector("[data-otp-resend]");
  var resendAt = Number(countdown.getAttribute("data-resend-at")) * 1000;
  var timer;
  function updateOtpCountdown() {
    var seconds = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
    countdown.textContent = String(Math.floor(seconds / 60)).padStart(2, "0")
      + ":" + String(seconds % 60).padStart(2, "0");
    if (seconds === 0) {
      button.disabled = false;
      countdown.parentElement.textContent = "You can request a new code now.";
      window.clearInterval(timer);
    }
  }
  button.disabled = true;
  timer = window.setInterval(updateOtpCountdown, 1000);
  updateOtpCountdown();
});

var cancelModal = document.getElementById("cancel-modal");
if (cancelModal) {
  var cancelForm = null;
  var cancelOffer = document.getElementById("cancel-modal-offer");
  function closeCancel() {
    cancelModal.hidden = true;
    cancelForm = null;
  }
  function openCancel(form) {
    cancelForm = form;
    cancelOffer.textContent = form.getAttribute("data-cancel-offer") || "";
    document.getElementById("cancel-modal-amount").textContent = form.getAttribute("data-cancel-amount") || "0";
    var band = form.getAttribute("data-cancel-band");
    cancelModal.querySelectorAll(".policy-steps li").forEach(function (item) {
      item.classList.toggle("on", item.getAttribute("data-band") === band);
    });
    cancelModal.hidden = false;
  }
  document.querySelectorAll("form[data-cancel-offer]").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      if (form.getAttribute("data-cancel-ready") === "1") {
        return;
      }
      event.preventDefault();
      openCancel(form);
    });
  });
  cancelModal.querySelector("[data-cancel-close]").addEventListener("click", closeCancel);
  cancelModal.addEventListener("click", function (event) {
    if (event.target === cancelModal) {
      closeCancel();
    }
  });
  cancelModal.querySelector("[data-cancel-go]").addEventListener("click", function () {
    if (!cancelForm) {
      return;
    }
    cancelForm.setAttribute("data-cancel-ready", "1");
    cancelForm.submit();
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && !cancelModal.hidden) {
      closeCancel();
    }
  });
}

var preview = document.getElementById("seat-preview");
var seatForm = document.querySelector(".seat-form");
var checkoutForm = document.querySelector(".checkout-form");
var holdClock = document.getElementById("hold-clock");
var holdClockTime = document.getElementById("hold-clock-time");
var holdDeadline = 0;
var holdLive = false;
var holdEnding = false;
var holdPaused = false;

function checkoutSeats() {
  if (!checkoutForm) return [];
  return Array.prototype.map.call(checkoutForm.querySelectorAll('input[name="seats[]"]'), function (input) {
    return input.value;
  });
}

function seatsAlreadyBooked(data) {
  var seats = checkoutSeats();
  if (!seats.length || !data || !data.taken) return false;
  return seats.every(function (seat) {
    return data.taken.indexOf(seat) !== -1;
  });
}
var lockNote = document.getElementById("seat-lock-text");
var lockRow = document.getElementById("seat-lock");
var paintSeats = function () {};

function setLock(message, tone) {
  if (lockNote) lockNote.textContent = message;
  if (lockRow) {
    lockRow.classList.toggle("is-ok", tone === "ok");
    lockRow.classList.toggle("is-warn", tone === "warn");
  }
}

function renderHold() {
  if (!holdClock) return;
  var left = holdDeadline > 0 ? Math.max(0, Math.round((holdDeadline - Date.now()) / 1000)) : 0;
  if (left <= 0) {
    if (holdLive && !holdEnding) {
      holdEnding = true;
      confirmHoldEnded();
    }
    if (!holdLive) holdClock.hidden = true;
    return;
  }
  holdEnding = false;
  holdLive = true;
  holdClock.hidden = false;
  var minutes = Math.floor(left / 60);
  var secs = left % 60;
  holdClockTime.textContent = minutes + ":" + (secs < 10 ? "0" : "") + secs;
  holdClock.classList.toggle("is-low", left <= 60);
}

function syncHold(seconds) {
  seconds = Math.max(0, parseInt(seconds, 10) || 0);
  holdDeadline = seconds > 0 ? Date.now() + seconds * 1000 : 0;
  renderHold();
}

function confirmHoldEnded() {
  if (!holdClock || holdPaused) return;
  var schedule = holdClock.getAttribute("data-schedule");
  fetch(holdClock.getAttribute("data-hold") + "?schedule=" + encodeURIComponent(schedule), { credentials: "same-origin" })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (holdPaused) {
        holdEnding = false;
        return;
      }
      if ((data.seconds || 0) > 0) {
        holdEnding = false;
        syncHold(data.seconds);
        if (seatForm) paintSeats(data);
        return;
      }
      if (seatsAlreadyBooked(data)) {
        holdLive = false;
        holdDeadline = 0;
        holdEnding = false;
        holdClock.hidden = true;
        return;
      }
      holdLive = false;
      holdDeadline = 0;
      holdClock.hidden = true;
      if (seatForm) {
        seatForm.querySelectorAll('input[name="seats[]"]:checked').forEach(function (input) {
          input.checked = false;
        });
        paintSeats(data);
        if (preview) preview.textContent = "None yet";
        setLock("Your 7-minute seat hold expired. Select seats again to restart checkout.", "warn");
        return;
      }
      window.location = "seats.php?schedule=" + encodeURIComponent(schedule) + "&hold=expired";
    })
    .catch(function () { holdEnding = false; });
}

if (checkoutForm) {
  checkoutForm.addEventListener("submit", function () {
    holdPaused = true;
    holdLive = false;
  });
}

if (holdClock) {
  syncHold(holdClock.getAttribute("data-seconds"));
  setInterval(renderHold, 250);
  if (!seatForm) {
    setInterval(function () {
      if (holdPaused || !holdClock) return;
      fetch(holdClock.getAttribute("data-hold") + "?schedule=" + encodeURIComponent(holdClock.getAttribute("data-schedule")), { credentials: "same-origin" })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (holdPaused || seatsAlreadyBooked(data)) {
            holdLive = false;
            holdDeadline = 0;
            holdClock.hidden = true;
            return;
          }
          syncHold(data.seconds || 0);
        })
        .catch(function () {});
    }, 3000);
  }
}

if (seatForm) {
  var holdUrl = seatForm.getAttribute("data-hold");
  var csrf = seatForm.querySelector("[name=csrf]").value;
  var scheduleId = seatForm.querySelector("[name=schedule]").value;

  var ticketMax = parseInt(seatForm.getAttribute("data-max") || "6", 10);
  var holdBlocked = false;
  var coachSelect = seatForm.querySelector("[data-coach-select]");
  var coachLabel = coachSelect ? coachSelect.querySelector("[data-coach-label]") : null;

  function setActiveCoach(coach) {
    if (!coachSelect) return;
    coachSelect.querySelectorAll("[data-coach]").forEach(function (btn) {
      var on = btn.getAttribute("data-coach") === coach;
      btn.classList.toggle("is-active", on);
      btn.setAttribute("aria-selected", on ? "true" : "false");
    });
    seatForm.querySelectorAll("[data-coach-panel]").forEach(function (panel) {
      panel.hidden = panel.getAttribute("data-coach-panel") !== coach;
    });
    if (coachLabel) coachLabel.textContent = coach;
  }

  function refreshCoachFullness(taken, held) {
    if (!coachSelect) return;
    var blocked = {};
    (taken || []).concat(held || []).forEach(function (seat) { blocked[seat] = true; });
    var active = null;
    var firstOpen = null;
    coachSelect.querySelectorAll("[data-coach]").forEach(function (chip) {
      var coach = chip.getAttribute("data-coach");
      var panel = seatForm.querySelector('[data-coach-panel="' + coach + '"]');
      var open = 0;
      if (panel) {
        panel.querySelectorAll('input[name="seats[]"]').forEach(function (input) {
          if (!blocked[input.value]) open += 1;
        });
      }
      var full = open === 0;
      chip.classList.toggle("is-full", full);
      chip.disabled = full;
      if (!full && !firstOpen) firstOpen = coach;
      if (chip.classList.contains("is-active")) active = coach;
    });
    if (active && coachSelect.querySelector('[data-coach="' + active + '"].is-full') && firstOpen) {
      setActiveCoach(firstOpen);
    }
  }

  if (coachSelect) {
    coachSelect.querySelectorAll("[data-coach]").forEach(function (chip) {
      chip.addEventListener("click", function () {
        if (chip.disabled || chip.classList.contains("is-full")) return;
        setActiveCoach(chip.getAttribute("data-coach"));
      });
    });
  }

  function chosenLabels() {
    return Array.prototype.map.call(
      seatForm.querySelectorAll('input[name="seats[]"]:checked'),
      function (input) { return input.value; }
    );
  }

  function showChosen() {
    var labels = chosenLabels();
    if (preview) preview.textContent = labels.length ? labels.join(", ") : "None yet";
  }

  paintSeats = function (data) {
    var taken = data.taken || [];
    var held = data.held || [];
    holdBlocked = !!data.blocked;
    seatForm.querySelectorAll('input[name="seats[]"]').forEach(function (input) {
      var label = input.closest(".seat");
      var blocked = taken.indexOf(input.value) !== -1;
      var reserved = held.indexOf(input.value) !== -1;
      label.classList.toggle("taken", blocked);
      label.classList.toggle("held", !blocked && reserved);
      input.disabled = blocked || reserved || holdBlocked;
      if (input.disabled && input.checked) {
        input.checked = false;
        setLock("Seat " + input.value + " is already selected by another passenger.", "warn");
      }
    });
    refreshCoachFullness(taken, held);
    if (holdBlocked) {
      setLock(data.message || "Finish your current booking or wait for its hold to expire before selecting seats for another trip.", "warn");
    } else if (lockNote && lockNote.dataset.holdBlocked === "1") {
      lockNote.dataset.holdBlocked = "0";
      setLock("Choosing a seat starts a 7 minute hold. The same time keeps running while you pay.", "");
    }
    if (lockNote) lockNote.dataset.holdBlocked = holdBlocked ? "1" : "0";
    showChosen();
  }

  function refreshSeats() {
    fetch(holdUrl + "?schedule=" + encodeURIComponent(scheduleId), { credentials: "same-origin" })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        paintSeats(data);
        syncHold(data.seconds || 0);
      })
      .catch(function () {});
  }

  seatForm.addEventListener("submit", function (event) {
    if (!chosenLabels().length) {
      event.preventDefault();
      setLock("Choose at least one seat.", "warn");
    }
  });

  seatForm.querySelectorAll('input[name="seats[]"]').forEach(function (input) {
    input.addEventListener("change", function () {
      if (input.checked && chosenLabels().length > ticketMax) {
        input.checked = false;
        showChosen();
        setLock(
          ticketMax === 0
            ? "You have booked 6 tickets in the last 24 hours."
            : "You can book " + ticketMax + " more " + (ticketMax === 1 ? "ticket" : "tickets") + " in the next 24 hours.",
          "warn"
        );
        return;
      }
      var body = new URLSearchParams();
      body.set("csrf", csrf);
      body.set("schedule", scheduleId);
      body.set("seat", input.value);
      body.set("keep", input.checked ? "1" : "0");
      fetch(holdUrl, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          paintSeats(data);
          if (data.ok && chosenLabels().length === 0) {
            holdLive = false;
            holdEnding = false;
          }
          syncHold(data.seconds || 0);
          if (!data.ok) {
            input.checked = false;
            showChosen();
            setLock(data.message || "That seat is already selected by another passenger.", "warn");
            return;
          }
          showChosen();
          var labels = chosenLabels();
          setLock(
            labels.length === 0
              ? "Choosing a seat starts a 7 minute hold. The same time keeps running while you pay."
              : labels.length === 1
                ? "Seat " + labels[0] + " is held for you."
                : "Seats " + labels.join(", ") + " are held for you.",
            labels.length ? "ok" : ""
          );
        })
        .catch(function () {
          input.checked = false;
          showChosen();
          setLock("Could not hold that seat. Try it again.", "warn");
        });
    });
  });

  refreshSeats();
  setInterval(refreshSeats, 3000);
}

function foldText(value) {
  return String(value || "").toLowerCase().replace(/[^a-z0-9]+/g, "");
}

function placeMatches(place, query) {
  var q = query.trim().toLowerCase();
  if (!q) return true;
  var name = place.name.toLowerCase();
  var code = place.code ? place.code.toLowerCase() : "";
  var foldedName = foldText(place.name);
  var foldedQuery = foldText(query);
  return name.indexOf(q) === 0
    || (code && code.indexOf(q) === 0)
    || (foldedQuery && foldedName.indexOf(foldedQuery) === 0)
    || (foldedQuery.length >= 3 && foldedName.indexOf(foldedQuery) !== -1);
}

function rankPlaces(pool, query) {
  var q = query.trim().toLowerCase();
  if (!q) return pool;
  var foldedQuery = foldText(query);
  var starts = pool.filter(function (place) {
    var name = place.name.toLowerCase();
    var code = place.code ? place.code.toLowerCase() : "";
    return name.indexOf(q) === 0
      || (code && code.indexOf(q) === 0)
      || (foldedQuery && foldText(place.name).indexOf(foldedQuery) === 0);
  });
  var contains = pool.filter(function (place) {
    return starts.indexOf(place) === -1 && placeMatches(place, query);
  });
  var sameLetter = pool.filter(function (place) {
    return place.name.toLowerCase().charAt(0) === q.charAt(0)
      && starts.indexOf(place) === -1
      && contains.indexOf(place) === -1;
  });
  return starts.concat(contains).concat(sameLetter);
}

function bindCitySuggest(form, cities, boxes, options) {
  options = options || {};
  var useHidden = !!options.useHidden;

  function otherName(box) {
    var other = box === boxes[0] ? boxes[1] : boxes[0];
    if (!other) return "";
    if (useHidden) {
      return other.querySelector("input[type=hidden]").value.trim().toLowerCase();
    }
    return other.querySelector(".suggest-text").value.trim().toLowerCase();
  }

  function ideas(query, box) {
    var blocked = otherName(box);
    var pool = cities.filter(function (place) {
      return place.name.toLowerCase() !== blocked;
    });
    return rankPlaces(pool, query);
  }

  boxes.forEach(function (box) {
    var text = box.querySelector(".suggest-text");
    var hidden = useHidden ? box.querySelector("input[type=hidden]") : null;
    var menu = box.querySelector(".suggest-list");
    var active = 0;
    var current = [];

    function choose(place) {
      if (place.name.toLowerCase() === otherName(box)) {
        text.value = "";
        if (hidden) hidden.value = "";
        text.dispatchEvent(new Event("change", { bubbles: true }));
        return;
      }
      text.value = place.name;
      if (hidden) hidden.value = place.name;
      menu.hidden = true;
      text.setAttribute("aria-expanded", "false");
      text.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function draw() {
      current = ideas(text.value, box);
      menu.innerHTML = "";
      if (!current.length) {
        menu.hidden = true;
        text.setAttribute("aria-expanded", "false");
        return;
      }
      var q = text.value.trim().toLowerCase();
      var foldedQuery = foldText(text.value);
      current.forEach(function (place, index) {
        var button = document.createElement("button");
        button.type = "button";
        button.setAttribute("role", "option");
        if (index === active) button.className = "on";
        var pin = document.createElement("span");
        pin.className = "pin";
        pin.textContent = place.name.charAt(0);
        var copy = document.createElement("span");
        var title = document.createElement("strong");
        var lower = place.name.toLowerCase();
        var hit = q && lower.indexOf(q) === 0 ? q.length : 0;
        if (!hit && foldedQuery && foldText(place.name).indexOf(foldedQuery) === 0) {
          hit = Math.min(place.name.length, Math.max(q.length, foldedQuery.length));
          while (hit > 0 && foldText(place.name.slice(0, hit)) !== foldedQuery && foldText(place.name.slice(0, hit)).indexOf(foldedQuery) !== 0) {
            hit -= 1;
          }
        }
        if (hit) {
          var mark = document.createElement("mark");
          mark.textContent = place.name.slice(0, hit);
          title.appendChild(mark);
          title.appendChild(document.createTextNode(place.name.slice(hit)));
        } else {
          title.textContent = place.name;
        }
        var detail = document.createElement("small");
        detail.textContent = place.line || "Bangladesh";
        copy.appendChild(title);
        copy.appendChild(detail);
        button.appendChild(pin);
        button.appendChild(copy);
        if (place.code) {
          var code = document.createElement("em");
          code.textContent = place.code;
          button.appendChild(code);
        }
        button.addEventListener("mousedown", function (event) {
          event.preventDefault();
          choose(place);
        });
        menu.appendChild(button);
      });
      menu.hidden = false;
      text.setAttribute("aria-expanded", "true");
    }

    text.addEventListener("focus", function () { active = 0; draw(); });
    text.addEventListener("input", function () {
      if (hidden) hidden.value = "";
      active = 0;
      draw();
    });
    text.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown") {
        active = Math.min(active + 1, current.length - 1);
        draw();
        event.preventDefault();
      } else if (event.key === "ArrowUp") {
        active = Math.max(active - 1, 0);
        draw();
        event.preventDefault();
      } else if (event.key === "Enter" && !menu.hidden && current[active]) {
        choose(current[active]);
        event.preventDefault();
      } else if (event.key === "Escape") {
        menu.hidden = true;
        text.setAttribute("aria-expanded", "false");
      }
    });
    text.addEventListener("blur", function () {
      var typed = text.value.trim().toLowerCase();
      var foldedTyped = foldText(text.value);
      var exact = cities.filter(function (place) {
        return place.name.toLowerCase() === typed
          || (place.code && place.code.toLowerCase() === typed)
          || (foldedTyped && foldText(place.name) === foldedTyped);
      })[0];
      if (exact && exact.name.toLowerCase() === otherName(box)) {
        text.value = "";
        if (hidden) hidden.value = "";
      } else if (exact) {
        choose(exact);
      }
      menu.hidden = true;
      text.setAttribute("aria-expanded", "false");
    });
  });
}

document.querySelectorAll("form").forEach(function (form) {
  var source = form.querySelector(".city-source");
  if (!source) return;
  var cities = [];
  try { cities = JSON.parse(source.textContent); } catch (error) { cities = []; }
  if (cities.length && typeof cities[0] === "string") {
    cities = cities.map(function (name) {
      return { name: name, code: "", line: "" };
    });
  }

  var passengerBoxes = form.querySelectorAll("[data-suggest]");
  var filterBoxes = form.querySelectorAll("[data-city-suggest]");

  if (passengerBoxes.length) {
    bindCitySuggest(form, cities, passengerBoxes, { useHidden: true });
  }
  if (filterBoxes.length) {
    bindCitySuggest(form, cities, filterBoxes, { useHidden: false });
  }

  var boxes = passengerBoxes;

  if (boxes.length === 2) {
    var fromValue = boxes[0].querySelector("input[type=hidden]").value.trim().toLowerCase();
    var toHidden = boxes[1].querySelector("input[type=hidden]");
    if (fromValue && fromValue === toHidden.value.trim().toLowerCase()) {
      toHidden.value = "";
      boxes[1].querySelector(".suggest-text").value = "";
    }
  }

  var swap = form.querySelector(".swap-btn");
  if (swap) {
    swap.addEventListener("click", function () {
      var boxes = form.querySelectorAll("[data-suggest]");
      var a = boxes[0].querySelector(".suggest-text");
      var b = boxes[1].querySelector(".suggest-text");
      var ah = boxes[0].querySelector("input[type=hidden]");
      var bh = boxes[1].querySelector("input[type=hidden]");
      var textValue = a.value;
      var hiddenValue = ah.value;
      a.value = b.value;
      ah.value = bh.value;
      b.value = textValue;
      bh.value = hiddenValue;
    });
  }

  form.querySelectorAll(".mode-switch a").forEach(function (link) {
    link.addEventListener("click", function (event) {
      event.preventDefault();
      var url = new URL(link.href, window.location.href);
      ["from", "to", "date"].forEach(function (name) {
        var field = form.querySelector("[name=" + name + "]");
        var value = field ? field.value.trim() : "";
        if (value) url.searchParams.set(name, value);
      });
      var chosenClass = form.querySelector(".class-field input[name=class]");
      if (chosenClass && chosenClass.value) url.searchParams.set("class", chosenClass.value);
      window.location.href = url.toString();
    });
  });

  form.addEventListener("submit", function (event) {
    var chosen = [];
    form.querySelectorAll("[data-suggest] input[type=hidden]").forEach(function (hidden) {
      chosen.push(hidden.value.trim().toLowerCase());
    });
    var same = chosen.length === 2 && chosen[0] && chosen[0] === chosen[1];
    if (chosen.indexOf("") !== -1 || same) {
      event.preventDefault();
      var first = form.querySelector("[data-suggest] .suggest-text");
      if (first) first.focus();
    }
  });
});

document.querySelectorAll("[data-class-menu]").forEach(function (box) {
  var source = box.querySelector(".class-source");
  var names = [];
  try { names = JSON.parse(source.textContent); } catch (error) { names = []; }
  var trigger = box.querySelector(".class-trigger");
  var value = box.querySelector(".class-value");
  var hidden = box.querySelector("input[type=hidden]");
  var menu = box.querySelector(".suggest-list");
  var active = 0;

  function close() {
    menu.hidden = true;
    box.classList.remove("is-open");
    trigger.setAttribute("aria-expanded", "false");
  }

  function choose(item) {
    hidden.value = item.value;
    value.textContent = item.label;
    close();
  }

  function draw() {
    menu.innerHTML = "";
    names.forEach(function (item, index) {
      var button = document.createElement("button");
      button.type = "button";
      button.setAttribute("role", "option");
      if (item.value === hidden.value) button.setAttribute("aria-selected", "true");
      if (index === active) button.className = "on";
      var pin = document.createElement("span");
      pin.className = "pin";
      pin.textContent = item.label.charAt(0);
      var strong = document.createElement("strong");
      strong.textContent = item.label;
      button.appendChild(pin);
      button.appendChild(strong);
      button.addEventListener("mousedown", function (event) {
        event.preventDefault();
        choose(item);
      });
      menu.appendChild(button);
    });
    menu.hidden = false;
    box.classList.add("is-open");
    trigger.setAttribute("aria-expanded", "true");
  }

  trigger.addEventListener("click", function () {
    if (menu.hidden) {
      active = names.findIndex(function (item) { return item.value === hidden.value; });
      if (active < 0) active = 0;
      draw();
    } else {
      close();
    }
  });
  trigger.addEventListener("keydown", function (event) {
    if (event.key === "ArrowDown") {
      active = menu.hidden ? Math.max(0, names.findIndex(function (item) { return item.value === hidden.value; })) : Math.min(active + 1, names.length - 1);
      if (active < 0) active = 0;
      draw();
      event.preventDefault();
    } else if (event.key === "ArrowUp" && !menu.hidden) {
      active = Math.max(active - 1, 0);
      draw();
      event.preventDefault();
    } else if (event.key === "Enter" && !menu.hidden && names[active]) {
      choose(names[active]);
      event.preventDefault();
    } else if (event.key === "Escape") {
      close();
    }
  });
  document.addEventListener("mousedown", function (event) {
    if (!box.contains(event.target)) close();
  });
});

document.querySelectorAll('input[name="nid_no"]').forEach(function (input) {
  input.addEventListener("input", function () {
    input.value = input.value.replace(/\D/g, "").slice(0, 10);
  });
});

document.querySelectorAll('input[name="full_name"]').forEach(function (input) {
  input.addEventListener("input", function () {
    input.value = input.value.replace(/[^\p{L}\s]/gu, "").replace(/^\s+/, "").replace(/\s{2,}/g, " ");
  });
});

document.querySelectorAll(".phone-field input").forEach(function (input) {
  input.addEventListener("input", function () {
    var digits = input.value.replace(/\D/g, "");
    if (digits.indexOf("880") === 0 && digits.length > 10) digits = digits.slice(3);
    if (digits.charAt(0) === "0" && digits.length === 11) digits = digits.slice(1);
    input.value = digits.slice(0, 10);
  });
});

document.querySelectorAll(".secret").forEach(function (wrap) {
  var input = wrap.querySelector("input");
  var button = wrap.querySelector(".eye");
  button.addEventListener("click", function () {
    var show = input.type === "password";
    input.type = show ? "text" : "password";
    button.setAttribute("aria-label", show ? "Hide password" : "Show password");
    button.setAttribute("aria-pressed", show ? "true" : "false");
    button.classList.toggle("is-open", show);
    input.focus();
  });
});

var routeSelect = document.getElementById("route-select");
var fareInput = document.getElementById("fare-input");
if (routeSelect && fareInput) {
  routeSelect.addEventListener("change", function () {
    var option = routeSelect.options[routeSelect.selectedIndex];
    fareInput.value = option.getAttribute("data-fare") || fareInput.value;
  });
}

var scheduleFrom = document.getElementById("schedule-from");
var scheduleTo = document.getElementById("schedule-to");
var scheduleFareMap = document.getElementById("schedule-route-fares");
if (scheduleFrom && scheduleTo && fareInput && scheduleFareMap) {
  var routeFares = {};
  try {
    routeFares = JSON.parse(scheduleFareMap.textContent || "{}") || {};
  } catch (error) {
    routeFares = {};
  }
  var scheduleForm = document.getElementById("schedule-form");
  var scheduleEditId = scheduleForm ? scheduleForm.querySelector('input[name="id"]') : null;
  var applyScheduleFare = function () {
    var fromVal = (scheduleFrom.value || "").trim().toLowerCase();
    var toVal = (scheduleTo.value || "").trim().toLowerCase();
    // Editing keeps this trip's fare. Route base fare fills a new schedule only.
    if (scheduleEditId && Number(scheduleEditId.value || 0) > 0) {
      return;
    }
    var key = fromVal + "|" + toVal;
    if (routeFares[key]) {
      fareInput.value = routeFares[key];
    }
  };
  scheduleFrom.addEventListener("change", applyScheduleFare);
  scheduleTo.addEventListener("change", applyScheduleFare);
  scheduleFrom.addEventListener("blur", applyScheduleFare);
  scheduleTo.addEventListener("blur", applyScheduleFare);
}

document.querySelectorAll("form").forEach(function (form) {
  var source = form.querySelector(".vehicle-source");
  var box = form.querySelector("[data-vehicle-suggest]");
  if (!source || !box) return;
  var vehicles = [];
  try { vehicles = JSON.parse(source.textContent || "[]") || []; } catch (error) { vehicles = []; }
  var text = box.querySelector(".suggest-text");
  var menu = box.querySelector(".suggest-list");
  if (!text || !menu) return;
  var spareOnly = box.hasAttribute("data-spare-only");
  var active = 0;
  var current = [];
  var pickedOk = false;

  var dateInput = form.querySelector("input[name=travel_date]");

  function tripWeekday() {
    if (!dateInput || !dateInput.value) return 0;
    var parts = dateInput.value.split("-");
    if (parts.length !== 3) return 0;
    var day = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    if (isNaN(day.getTime())) return 0;
    return day.getDay() + 1;
  }

  function matchVehicle(row, query, foldedQuery) {
    if (!query) return true;
    var code = (row.code || "").toLowerCase();
    var name = (row.name || "").toLowerCase();
    return code.indexOf(query) !== -1
      || name.indexOf(query) !== -1
      || (foldedQuery && foldText(row.code || "").indexOf(foldedQuery) !== -1)
      || (foldedQuery && foldText(row.name || "").indexOf(foldedQuery) !== -1);
  }

  function findExact(typedRaw) {
    var typed = (typedRaw || "").trim().toLowerCase();
    var foldedTyped = foldText(typedRaw || "");
    var weekday = tripWeekday();
    if (!typed) return null;
    return vehicles.filter(function (row) {
      if (!spareOnly && row.line !== "Current vehicle" && weekday && Number(row.day_off || 0) === weekday) return false;
      return (row.code || "").toLowerCase() === typed
        || (row.name || "").toLowerCase() === typed
        || (foldedTyped && foldText(row.code || "") === foldedTyped)
        || (foldedTyped && foldText(row.name || "") === foldedTyped);
    })[0] || null;
  }

  function ideas(query) {
    var q = (query || "").trim().toLowerCase();
    var folded = foldText(query || "");
    var weekday = tripWeekday();
    return vehicles.filter(function (row) {
      if (row.line === "Current vehicle") return matchVehicle(row, q, folded);
      if (!spareOnly && weekday && Number(row.day_off || 0) === weekday) return false;
      return matchVehicle(row, q, folded);
    }).slice(0, 80);
  }

  function choose(row) {
    text.value = row.code || row.name || "";
    pickedOk = true;
    text.setCustomValidity("");
    menu.hidden = true;
    text.setAttribute("aria-expanded", "false");
    text.dispatchEvent(new Event("change", { bubbles: true }));
  }

  function draw() {
    current = ideas(text.value);
    menu.innerHTML = "";
    if (!current.length) {
      menu.hidden = true;
      text.setAttribute("aria-expanded", "false");
      return;
    }
    current.forEach(function (row, index) {
      var button = document.createElement("button");
      button.type = "button";
      button.setAttribute("role", "option");
      if (index === active) button.className = "on";
      var pin = document.createElement("span");
      pin.className = "pin";
      pin.textContent = (row.name || row.code || "?").charAt(0);
      var copy = document.createElement("span");
      var title = document.createElement("strong");
      title.textContent = row.name || row.code;
      var detail = document.createElement("small");
      detail.textContent = row.line || "Available";
      copy.appendChild(title);
      copy.appendChild(detail);
      button.appendChild(pin);
      button.appendChild(copy);
      if (row.code) {
        var code = document.createElement("em");
        code.textContent = row.code;
        button.appendChild(code);
      }
      button.addEventListener("mousedown", function (event) {
        event.preventDefault();
        choose(row);
      });
      menu.appendChild(button);
    });
    menu.hidden = false;
    text.setAttribute("aria-expanded", "true");
  }

  text.addEventListener("focus", function () { active = 0; draw(); });
  text.addEventListener("input", function () {
    pickedOk = false;
    text.setCustomValidity("");
    active = 0;
    draw();
  });
  if (dateInput) {
    dateInput.addEventListener("change", function () { active = 0; draw(); });
  }
  text.addEventListener("keydown", function (event) {
    if (event.key === "ArrowDown") {
      active = Math.min(active + 1, Math.max(current.length - 1, 0));
      draw();
      event.preventDefault();
    } else if (event.key === "ArrowUp") {
      active = Math.max(active - 1, 0);
      draw();
      event.preventDefault();
    } else if (event.key === "Enter" && !menu.hidden && current[active]) {
      choose(current[active]);
      event.preventDefault();
    } else if (event.key === "Escape") {
      menu.hidden = true;
      text.setAttribute("aria-expanded", "false");
    }
  });
  text.addEventListener("blur", function () {
    var exact = findExact(text.value);
    if (exact) {
      choose(exact);
    } else if (spareOnly && text.value.trim()) {
      text.value = "";
      pickedOk = false;
      text.setCustomValidity("Select a spare from the list. Active and maintenance vehicles cannot be used.");
    }
    menu.hidden = true;
    text.setAttribute("aria-expanded", "false");
  });
  if (spareOnly) {
    form.addEventListener("submit", function (event) {
      var exact = findExact(text.value);
      if (!exact) {
        text.value = "";
        pickedOk = false;
        text.setCustomValidity("Select a spare from the list. Active and maintenance vehicles cannot be used.");
        text.reportValidity();
        event.preventDefault();
      } else {
        choose(exact);
      }
    });
  }
});
