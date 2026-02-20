/* ============================================
   ADIL & ASMA — WEDDING INVITATION
   Video Intro + Cascade Reveal Animations
   ============================================ */

document.addEventListener("DOMContentLoaded", () => {
    const overlay = document.getElementById("intro-overlay");
    const video = document.getElementById("intro-video");
    const prompt = document.getElementById("intro-prompt");
    const invitation = document.getElementById("invitation");

    const fadeElements = document.querySelectorAll(".anim-fade");

    let introState = "idle"; // idle → playing → done

    // ---- Intro: tap to play ----
    overlay.addEventListener("click", handleIntroClick);

    function handleIntroClick() {
        if (introState !== "idle") return;
        introState = "playing";

        // Hide the prompt
        prompt.classList.add("hidden");

        // Play the video
        video.play().catch(() => finishIntro());

        // Safety timeout — if video never fires "ended" after 60s, force finish
        setTimeout(() => finishIntro(), 60000);
    }

    // When video ends naturally, transition to the invitation
    video.addEventListener("ended", finishIntro);

    function finishIntro() {
        if (introState === "done") return;
        introState = "done";

        // Last frame is white — remove overlay seamlessly
        overlay.classList.add("done");
        startCascadeReveal();
    }

    // ---- Cascade Reveal: fountain → content ----
    function startCascadeReveal() {
        // Step 1: Fountain background fades in from bottom
        requestAnimationFrame(() => {
            invitation.classList.add("reveal-bg");
        });

        // Step 2: Text elements fade in from top (staggered, starting after 700ms)
        setTimeout(() => {
            fadeElements.forEach((el) => {
                const delayIndex = parseInt(el.dataset.delay, 10) || 1;
                setTimeout(() => {
                    el.classList.add("revealed");
                }, delayIndex * 350);
            });
        }, 700);
    }

    /* ============================================
       RSVP SYSTEM
       ============================================ */

    const rsvpSection = document.getElementById("rsvp-section");

    // Views
    const viewSearch = document.getElementById("rsvp-search");
    const viewForm = document.getElementById("rsvp-form");
    const viewSuccess = document.getElementById("rsvp-success");
    const viewAlready = document.getElementById("rsvp-already");

    // Search elements
    const nameInput = document.getElementById("rsvp-name-input");
    const searchBtn = document.getElementById("rsvp-search-btn");
    const searchError = document.getElementById("rsvp-search-error");
    const candidatesWrap = document.getElementById("rsvp-candidates");
    const candidateList = document.getElementById("rsvp-candidate-list");

    // Form elements
    const formEl = document.getElementById("rsvp-form-el");
    const guestIdInput = document.getElementById("rsvp-guest-id");
    const guestNameEl = document.getElementById("rsvp-guest-name");
    const preWedField = document.getElementById("rsvp-pre-wedding-field");
    const plusOneField = document.getElementById("rsvp-plus-one-field");
    const plusOneNameWrap = document.getElementById("rsvp-plus-one-name-wrap");
    const plusOneNameInput = document.getElementById("rsvp-plus-one-name");
    const submitBtn = document.getElementById("rsvp-submit-btn");
    const backBtn = document.getElementById("rsvp-back-btn");

    // Already view
    const alreadyNameEl = document.getElementById("rsvp-already-name");

    let csrfToken = null;
    let currentGuest = null;

    // ---- Fetch CSRF token ----
    async function getToken() {
        if (csrfToken) return csrfToken;
        try {
            const res = await fetch("api/token.php");
            const data = await res.json();
            csrfToken = data.token;
            return csrfToken;
        } catch {
            return null;
        }
    }

    // ---- Show a specific view ----
    function showView(view) {
        [viewSearch, viewForm, viewSuccess, viewAlready].forEach(v => v.classList.remove("active"));
        view.classList.add("active");
    }


    // ---- Search ----
    async function doSearch() {
        const name = nameInput.value.trim();
        if (!name || name.length < 2) {
            showError("Please enter at least 2 characters.");
            return;
        }

        searchBtn.disabled = true;
        searchBtn.textContent = "Searching...";
        hideError();
        candidatesWrap.hidden = true;

        const token = await getToken();
        if (!token) {
            showError("Could not connect to server. Please try again.");
            searchBtn.disabled = false;
            searchBtn.textContent = "Search";
            return;
        }

        try {
            const res = await fetch("api/guest-lookup.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-RSVP-Token": token,
                },
                body: JSON.stringify({ name }),
            });

            // Token may have expired — refresh and retry once
            if (res.status === 403) {
                csrfToken = null;
                const newToken = await getToken();
                if (newToken) {
                    const retry = await fetch("api/guest-lookup.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-RSVP-Token": newToken,
                        },
                        body: JSON.stringify({ name }),
                    });
                    return handleSearchResponse(await retry.json());
                }
            }

            if (res.status === 429) {
                showError("Too many requests. Please wait a moment and try again.");
                return;
            }

            const data = await res.json();
            if (data.error) {
                showError(data.error);
                return;
            }

            handleSearchResponse(data);
        } catch {
            showError("Could not connect to server. Please try again.");
        } finally {
            searchBtn.disabled = false;
            searchBtn.textContent = "Search";
        }
    }

    function handleSearchResponse(data) {
        if (data.already_submitted) {
            alreadyNameEl.textContent = data.guest_name || "";
            showView(viewAlready);
            return;
        }

        if (data.guest) {
            loadGuestForm(data.guest);
            return;
        }

        if (data.candidates && data.candidates.length > 0) {
            showCandidates(data.candidates);
            return;
        }

        showError("Name not found. Please enter your name as it appears on your invitation.");
    }

    function showCandidates(candidates) {
        candidateList.innerHTML = "";
        candidates.forEach(c => {
            const li = document.createElement("li");
            li.textContent = c.name;
            if (c.already_submitted) {
                li.classList.add("submitted");
                li.textContent += " (already submitted)";
            } else {
                li.addEventListener("click", () => selectCandidate(c.id));
            }
            candidateList.appendChild(li);
        });
        candidatesWrap.hidden = false;
    }

    async function selectCandidate(guestId) {
        const token = await getToken();
        if (!token) return;

        try {
            const res = await fetch("api/guest-lookup.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-RSVP-Token": token,
                },
                body: JSON.stringify({ guest_id: guestId }),
            });
            const data = await res.json();
            handleSearchResponse(data);
        } catch {
            showError("Could not connect to server.");
        }
    }

    // ---- Load guest into form ----
    function loadGuestForm(guest) {
        currentGuest = guest;
        guestIdInput.value = guest.id;
        guestNameEl.textContent = guest.name;

        // Show/hide conditional fields
        preWedField.hidden = !guest.pre_wedding_invited;
        plusOneField.hidden = !guest.plus_one_allowed;
        plusOneNameWrap.hidden = true;

        // Reset radio buttons
        formEl.querySelectorAll('input[type="radio"]').forEach(r => {
            if (r.name === "attending_wedding" && r.value === "1") r.checked = true;
            if (r.name === "attending_pre_wedding" && r.value === "1") r.checked = true;
            if (r.name === "plus_one_coming" && r.value === "0") r.checked = true;
        });

        showView(viewForm);
    }

    // ---- Plus-one radio toggle ----
    document.querySelectorAll('input[name="plus_one_coming"]').forEach(r => {
        r.addEventListener("change", () => {
            plusOneNameWrap.hidden = r.value !== "1" || !r.checked;
        });
    });

    // ---- Back to search ----
    if (backBtn) {
        backBtn.addEventListener("click", () => {
            showView(viewSearch);
            currentGuest = null;
        });
    }

    // ---- Submit RSVP ----
    if (formEl) {
        formEl.addEventListener("submit", async (e) => {
            e.preventDefault();

            const token = await getToken();
            if (!token) {
                showError("Could not connect to server.");
                return;
            }

            const formData = new FormData(formEl);
            const body = {
                guest_id: parseInt(formData.get("guest_id"), 10),
                attending_wedding: formData.get("attending_wedding") === "1",
                attending_pre_wedding: currentGuest.pre_wedding_invited
                    ? formData.get("attending_pre_wedding") === "1"
                    : false,
                plus_one_coming: currentGuest.plus_one_allowed
                    ? formData.get("plus_one_coming") === "1"
                    : false,
                plus_one_name: "",
            };

            if (body.plus_one_coming) {
                body.plus_one_name = plusOneNameInput.value.trim();
            }

            submitBtn.disabled = true;
            submitBtn.textContent = "Submitting...";

            try {
                const res = await fetch("api/send-rsvp.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-RSVP-Token": token,
                    },
                    body: JSON.stringify(body),
                });

                const data = await res.json();

                if (res.status === 409 || data.already_submitted) {
                    alreadyNameEl.textContent = currentGuest.name;
                    showView(viewAlready);
                    return;
                }

                if (data.error) {
                    showError(data.error);
                    return;
                }

                if (data.success) {
                    showView(viewSuccess);
                }
            } catch {
                showError("Could not submit. Please try again.");
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = "Submit RSVP";
            }
        });
    }

    // ---- Search event listeners ----
    if (searchBtn) {
        searchBtn.addEventListener("click", doSearch);
    }
    if (nameInput) {
        nameInput.addEventListener("keydown", (e) => {
            if (e.key === "Enter") {
                e.preventDefault();
                doSearch();
            }
        });
    }

    // ---- Helpers ----
    function showError(msg) {
        searchError.textContent = msg;
        searchError.hidden = false;
    }
    function hideError() {
        searchError.hidden = true;
        searchError.textContent = "";
    }
});
