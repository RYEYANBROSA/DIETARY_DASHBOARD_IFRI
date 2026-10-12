Build a new web app in the "Forest" dashboard theme described below. Replace every [BRACKETED] value with mine before you start; ask me only if a bracket is still unfilled.

PROJECT
- App name: [APP NAME] — organization: [ORGANIZATION, e.g. DOST-FNRI]
- Folder: [PATH, e.g. C:\wamp64\www\laravel_projects\my_app]
- Modules (sidebar order) and which levels open each by default:
  [e.g. Dashboard (all) · Module A (admin, validator) · Module B (admin, client) · My Profile (all) · System Users (admin)]
- Data source for the module pages: [database/connection, read-only or not]

STACK (fixed)
- Laravel 12, Livewire 3 (pin livewire/livewire ^3 — NOT v4), Tailwind CSS 4 via @tailwindcss/vite, Alpine (bundled with Livewire). No other UI framework.
- Class-based full-page Livewire components with #[Layout] / #[Title]; Blade components for shared UI.
- MySQL on WAMP: set the mysql connection 'engine' => 'InnoDB' (WAMP defaults to MyISAM, which breaks utf8mb4 unique indexes).

THEME TOKENS (resources/css/app.css @theme)
- Font: "Plus Jakarta Sans" (Google Fonts, weights 400–800) as --font-sans.
- --color-forest-950 #0B2E22 · forest-900 #0F3D2E (sidebar, brand panel, dark KPI card) · forest-800 #164C39 (hover, user card) · forest-700 #1F6F4A (active nav item)
- --color-mint-300 #86EFAC (avatar fill, accents on dark) · mint-200 #A7D3B9 (secondary text on dark) · mint-100 #CFE6D8 (nav text on dark)
- --color-canvas #F3F7F2 (page background) · line #E1ECE4 (card borders) · field #CFE0D4 (input borders)
- --color-ink #0F1F17 (text) · ink-soft #2D4237 (labels) · muted #4B5E54 (secondary text)
- Primary action color: Tailwind green-700 (#15803D), hover green-800; success chips green-100/green-900; logo tile green-500 with forest-900 icon.
- Status colors: warning amber-100/amber-900, error red-700 text / red-600 border, Validator-type badge blue-100/blue-900.
- Add [x-cloak]{display:none !important}.

LAYOUT
- App shell: fixed 264px forest-900 sidebar (sticky, full height) + main area on canvas, padding 28–32px, gap 24px.
  - Sidebar top: 40px rounded green-500 logo tile with a leaf icon, app name (white, bold) and organization (mint-200).
  - "MODULES" label (11.5px, bold, tracking 0.1em), then nav links 44px tall, radius 10px, icon + label; active = forest-700 bg, white, bold; inactive = mint-100 text, hover forest-800.
  - Only show modules the signed-in user can open.
  - Bottom: user card (forest-800, radius 14px) with initials avatar (mint-300 circle), name, level, and a "Sign out" button (POST form).
  - Below lg: sidebar becomes a slide-over opened from a white top bar with a hamburger button.
- Page header component: 26px extrabold title + 14px muted subtitle, optional actions on the right.
- Cards: white, 1px line border, radius 16px, padding 20–24px. KPI tiles: 13px muted label, 32px extrabold number, 13px muted note; one highlighted KPI uses forest-900 bg with a mint-300 progress bar.
- Charts: no chart library — horizontal bars made of divs (12px tall, radius 6px, track #EEF5F0, fill green-700 or green-500) with label / bar / value columns; stacked "mix" bars for shares.
- Tables: inside an overflow-x-auto box with border + radius 12px; header row #F6FAF7 with 12px bold muted text; rows 12px/14px padding with #EEF3EF top borders; pill badges (rounded-full, 12px bold).
- Add/Edit forms for records open in a right slide-over (max-w 420px, white, shadow-2xl, forest-950/40 backdrop, Esc closes) so tables keep their full width.
- Toasts: bottom-right, forest-900 (success) / red-700 (error) / white bordered (info), auto-dismiss 5s; components call $this->dispatch('toast', message: ..., type: ...).
- Empty states: centered icon tile + bold line + muted explanation. Never use lorem ipsum or invented numbers.
- Icons: one <x-icon name="..."> Blade component with inline 24x24 stroke SVGs (stroke-width 1.8, currentColor). No emoji, no icon fonts.

AUTH SCREENS (guest layout)
- Split screen: left 44% (max 620px) forest-900 brand panel — logo, eyebrow (mint-300, tracking), 42px extrabold white headline, short paragraph, 3 feature rows with icon tiles, organization footer; hidden below lg.
- Right: centered white card (max 580px, radius 24px, soft green shadow) with a two-tab switch (Sign in / Register) on a #EEF5F0 track.
- Pages: Login (remember me, "Forgot password?"), Register, Verify-email notice (resend, throttled 1/min), Forgot password (same answer whether or not the email exists), Reset password.

ACCOUNTS & ACCESS
- users: first_name, middle_name (nullable), last_name, email (unique — it is the username), mobile_number (nullable), level, modules (JSON), is_active, email_verified_at, password, last_login_at, created_by.
- Levels enum: CLIENT, VALIDATOR, ADMINISTRATOR. Registration always creates CLIENT. Each level has default modules; administrators always have every module; System Users is administrator-only.
- System Users page: counts per level + "awaiting verification", searchable/filterable paginated table with a MODULE ACCESS checkbox column per module (toggles instantly; locked for administrators), Edit button, slide-over form (names, email, mobile, level select that resets module boxes to that level's defaults, module checkboxes, active toggle when editing). An administrator cannot demote or deactivate themselves.
- New account from System Users: random unusable password, email marked verified, ONE welcome email with a set-password link (password broker token). Editing sends nothing.
- Middleware: module:<key> (403 without access) and active (signs out deactivated accounts); register both as Livewire persistent middleware. Unverified users go to the verify notice. A user with no modules lands on a "No modules assigned yet" page.
- Artisan command user:make-admin {email} to promote the first administrator. Local-only seeder with one demo account per level.

FORMS & VALIDATION
- Every required field shows exactly "This field is required." (set in lang/en/validation.php after php artisan lang:publish).
- Fields are checked when the user leaves them (wire:model.blur + validateOnly in a ValidatesOnBlur trait): error = red border + red message; pass = green border + check icon (+ a green note for email/mobile).
- Email: rules required|string|email:rfc,filter|max:255 (+ unique where needed); store lowercase/trimmed. Messages: "Please enter a valid email address." / "This email address is already registered." / "Valid email address."
- Mobile: nullable|digits:11|starts_with:09; input is digits-only (strip non-digits as typed, maxlength 11, inputmode numeric). Messages: "Mobile number must be exactly 11 digits (e.g. 09171234567)." / "Mobile number must start with 09." / "Valid mobile number."
- Passwords: Password::min(8)->mixedCase()->numbers()->symbols() as the default; every password input has a show/hide eye button; new-password fields show a live strength meter (5 segments, Too weak → Strong) and a checklist (8+ characters, uppercase, lowercase, number, special character). The meter is bound to the Livewire property with $wire.entangle so it clears when the server resets the field.
- Shared rules live in one AccountRules class used by registration, profile and System Users.
- Inputs: 44px tall, radius 10px, field border, focus green-700 border + soft green ring; labels 13px semibold ink-soft. Forms use novalidate (server messages only).
- Primary buttons: green-700, white bold text, radius 11–12px, 44–48px tall, wire:loading text ("Saving…").

PROFILE PAGE
- Left summary card (forest-900 banner, 80px initials avatar, name, email, level + "Email verified" badges, mobile, member since, last sign-in, the user's modules). Right: Account details form (first/middle/last name, email, mobile — changing email requires re-verification) and Change password form (current password, new + confirm with strength checklist, must differ from current).

EMAIL
- Gmail SMTP: smtp.gmail.com, port 587, MAIL_USERNAME [SENDER GMAIL], MAIL_PASSWORD [GMAIL APP PASSWORD] — only in .env, never committed. MAIL_FROM_NAME "[ORGANIZATION] [APP NAME]".
- One branded HTML template for every email (table layout, inline styles): forest-900 header with logo tile + app name, heading, greeting, lines, one green-700 button, muted outro, raw link fallback in a #F6FAF7 footer, organization line underneath.
- Custom notifications extending Laravel's VerifyEmail and ResetPassword + an AccountCreated notification, all using that template. The verification link works without being signed in (signed URL + email hash).
- Wrap every send in try/catch: an SMTP failure must never lose the record that was just saved; tell the user how to retry.

QUALITY BAR
- Accessible: real <button>/<a>/<label>, aria-label on icon-only buttons, aria-current on the active nav item, 44px touch targets, text contrast ≥ 4.5:1. Works at phone width.
- Feature tests (PHPUnit, sqlite in-memory) for registration, verification, login (unverified/inactive/rate-limited), password reset, module access per level, System Users (create sends one email, edit sends none, self-demotion blocked, checkbox toggles), profile, and the validation messages above.
- Build with npm run build, run php artisan test, then open the app in the browser and check the auth screens, dashboard, a table page and System Users before reporting back.
