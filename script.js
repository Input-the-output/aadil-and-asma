/* ============================================
   ADIL & ASMA — WEDDING INVITATION
   Video Intro → vid.gif (once) → looping vid.gif + text
   ============================================ */

document.addEventListener("DOMContentLoaded", () => {
    const overlay = document.getElementById("intro-overlay");
    const video = document.getElementById("intro-video");
    const prompt = document.getElementById("intro-prompt");
    const invitation = document.getElementById("invitation");
    const bgGif = document.getElementById("bg-gif");

    const fadeElements = document.querySelectorAll(".anim-fade");

    // vid.gif is ~4.8s (48 frames at 10fps)
    const VID_GIF_DURATION = 4800;

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
        startGifSequence();
    }

    // Preload looping GIF so swap is instant
    const loopGif = new Image();
    loopGif.src = "assets/looping%20vid.gif";

    // ---- GIF Sequence: vid.gif (once) → looping vid.gif + text ----
    function startGifSequence() {
        // Step 1: Load vid.gif and show it clearly (no overlay)
        bgGif.src = "assets/vid.gif";
        requestAnimationFrame(() => {
            bgGif.classList.add("revealed");
        });

        // Step 2: After vid.gif finishes (~4.8s), swap to looping vid, fade in overlay, show text
        // Swap slightly before the loop restarts to avoid the twitch
        setTimeout(() => {
            bgGif.src = loopGif.src;
            invitation.classList.add("reveal-bg");
            startTextReveal();
        }, VID_GIF_DURATION - 200);
    }

    // ---- Text Reveal: slow staggered fade-in from top ----
    function startTextReveal() {
        const content = document.querySelector(".invitation-content");

        // Show floral border frames
        invitation.classList.add("show-frames");

        fadeElements.forEach((el) => {
            const delayIndex = parseInt(el.dataset.delay, 10) || 1;
            setTimeout(() => {
                el.classList.add("revealed");
            }, delayIndex * 450);
        });
    }
});
