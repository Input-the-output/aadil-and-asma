# Adil & Asma — Wedding Website

Wedding invitation website with video intro, cascade reveal animations, and a full RSVP system with fuzzy name search.

**Wedding Date:** March 28, 2026
**Venue:** Sursock Palace

---

## Architecture

- **Frontend:** Vanilla HTML/CSS/JS — no frameworks, no build step
- **Backend:** PHP API endpoints reading/writing local JSON files
- **Data:** Guest list exported from Excel → `data/guests.json`; RSVPs stored in `data/rsvps.json`

No database required. Everything runs on a standard LAMP/Apache setup.

## File Structure

```
adil-and-asma/
├── index.html              # Main page — intro overlay + invitation + RSVP section
├── style.css               # All styles (invitation, animations, RSVP)
├── script.js               # Video intro, cascade reveal, RSVP client logic
├── .htaccess               # Blocks data/, sensitive files; security headers; caching
├── assets/                 # Florals, wreath, gate-opening video
│   ├── gate-opening.mp4
│   ├── top-left.png, top-right.png, bottom-left.png, bottom-right.png
│   └── middle.png
├── data/
│   ├── convert-excel.py    # One-time script: Excel → guests.json
│   ├── guests.json         # 290 guests with fuzzy matching metadata (read-only)
│   └── rsvps.json          # RSVP submissions (written by PHP)
└── api/
    ├── .htaccess           # Blocks config.php, rate_limits/, dotfiles
    ├── config.php           # Secrets & settings (gitignored)
    ├── config.example.php  # Template for config.php
    ├── token.php           # GET — CSRF token generator (HMAC-signed, 10min TTL)
    ├── guest-lookup.php    # POST — Fuzzy name search + already-submitted check
    └── send-rsvp.php       # POST — RSVP submission with flock() concurrency
```

## Features

### Invitation
- Full-screen video intro (gate opening) with "Tap to Open" prompt
- Cascade reveal animation — corners fly in, text fades in sequentially
- Concrete/plaster texture overlay via SVG noise filter
- Responsive across phones, tablets, landscape

### RSVP System
- **Fuzzy name search** — handles misspellings, partial names, phonetic matches
  - Pipeline: exact match → metaphone → Levenshtein (distance ≤ 2) → substring → weighted scoring (threshold ≥ 50)
  - "George" finds "Georges Keyrouz"; "Eli" finds both "Elie Hamouche" and "Elie Kareh"
- **"Did you mean?" candidate list** when multiple matches found
- **Conditional form fields** — pre-wedding checkbox only for invited guests, plus-one only when allowed
- **One-time submission** — 409 on duplicate, "already submitted" view
- **Radio toggle UI** — visual feedback on selected option

### Security
- CSRF tokens (HMAC-SHA256, 10min TTL) on all POST endpoints
- Origin header validation (configurable allowed origins)
- Rate limiting per IP (10 RPM lookup, 5 RPM submit)
- `data/` directory blocked from web access via .htaccess
- Input sanitization (strip_tags, regex validation)
- File locking (`flock(LOCK_EX)`) for concurrent RSVP writes

## Setup

1. **Generate guest list** (only needed when Excel changes):
   ```bash
   pip3 install openpyxl jellyfish
   python3 data/convert-excel.py
   ```

2. **Configure API**:
   ```bash
   cp api/config.example.php api/config.php
   # Edit config.php — set token_secret and allowed_origins
   ```

3. **Set permissions**:
   ```bash
   chmod 666 data/rsvps.json
   ```

4. **Deploy** — Apache with mod_rewrite enabled. No other dependencies.

## Current Status

- [x] Video intro + cascade reveal animations
- [x] Invitation layout with florals, names, date, venue
- [x] Text renders above concrete texture (`isolation: isolate`)
- [x] RSVP button in invitation → scrolls to RSVP section
- [x] Guest search with fuzzy matching (290 guests loaded)
- [x] RSVP form with conditional fields (wedding, pre-wedding, plus-one)
- [x] Backend API (token, lookup, submit) with JSON file storage
- [x] Security (CSRF, origin validation, rate limiting, .htaccess)
- [ ] End-to-end browser testing
- [ ] Production domain in config.php allowed_origins
- [ ] Email notifications on RSVP submission (not yet implemented)
# aadil-and-asma
