# IBEMS UI Conventions

This document is the source of truth for authenticated IBEMS interface work. New screens should reuse these conventions before introducing page-specific markup or styles.

## Design Principles

1. Keep the active portal and user role obvious.
2. Put the primary task first and secondary actions behind clear labels.
3. Show financial and inventory state with explicit labels, units, and status.
4. Use the shared components and classes before adding page-specific variants.
5. Preserve keyboard access, visible focus, readable contrast, and mobile usability.
6. Give every asynchronous surface a loading, empty, error, and success state.

## Visual Foundation

The canonical global stylesheet is `public/assets/css/app.css`.
The modern presentation layer is `public/assets/css/modern-ui.css`; it is loaded after the compatibility stylesheet and is the preferred visual direction for new authenticated UI.

- Brand colors: use the `--ustp-*` variables.
- Surfaces and text: use the shared `--surface-*`, `--text-*`, `--border`, and `--line-soft` variables.
- Font stack: Inter for product UI and JetBrains Mono for compact metadata or technical identifiers.
- Icons: Bootstrap Icons.
- Page-specific styles may extend the system but should not redefine global button, table, modal, or status behavior.

Tailwind utilities are available for focused composition. Shared product conventions still belong in `app.css` so server-rendered and JavaScript-rendered interfaces remain visually aligned.

## Page Shell

Authenticated pages extend one of the role layouts under `app/Views/layouts/`.

Those role layouts configure the shared `app/Views/components/portal_shell.php`. The shared shell owns:

- CSS and JavaScript assets
- CSRF metadata
- Sidebar and navigation rendering
- Topbar, role switching, and profile presentation
- Main content container
- Portal footer

Do not copy the shell into a new portal. Add a small role layout that supplies portal labels and a navigation array.

## Reusable Components

### Page headers

Use `app/Views/components/page_header.php` for primary page hierarchy.

- `eyebrow` identifies the workspace or business context.
- `title` names the current task or view.
- `description` explains the purpose in one concise sentence.
- `icon` is optional and decorative.
- `actions` accepts reviewed action markup aligned to the right on larger screens.

### Metric cards

Use `app/Views/components/stat_card.php` for dashboard and summary values.

Required input:

- `title`
- `value` or `valueHtml`
- `icon`
- `tone`

Use stable element IDs only when JavaScript updates the value.

### Data states

Use `app/Views/components/data_state.php` for initial loading, empty, error, and success presentation.

- Set `type` to `loading`, `empty`, `error`, or `success`.
- Provide a specific `message`; add `detail` when recovery or context is useful.
- For table bodies, pass `tag` as `tr` and set the correct `colspan`.
- JavaScript replacements should retain the same `.data-state` structure and semantics.

### Buttons

Use the shared button system. All ordinary action buttons must use one semantic variant; page styles must not redefine these classes:

- `.btn-primary` or `.primary-btn` for the main action
- `.btn-secondary` or `.secondary-btn` for neutral actions
- `.btn-danger` or `.danger-btn` for destructive actions
- `.btn-ghost` for low-emphasis actions
- `.btn-sm` for compact table actions
- `.btn-icon` with a semantic variant for icon-only actions

Buttons must use an actual `<button>` for actions and an `<a>` for navigation. Icon-only controls require an accessible label.
Tabs, segmented filters, calendar navigation, suggestion options, quantity steppers, and other specialized controls may retain their component class, but they must inherit the global focus and disabled behavior. Do not use page-specific color, radius, hover, or loading rules for ordinary actions.

### Tables

Use `.table.table-standard` inside `.table-standard-wrap`.

When a table needs a visible section title, use the shared panel anatomy rather
than placing a heading directly inside the scroll wrapper:

```html
<section class="data-panel">
    <header class="data-panel-head"><h4>Section title</h4></header>
    <div class="data-panel-body table-standard-wrap">
        <table class="table table-standard">...</table>
    </div>
</section>
```

Use `.dash-panel` for non-table content cards. Do not use a table wrapper as a
generic content panel.

For repeated rich records that are not tabular, use one `.record-panel`
containing `.record-list`; each generated record uses `.record-list-item`.
The panel owns the outside boundary and items use internal separators. Do not
give every record its own rounded outer border.

- Put search and filters before the table.
- Give sortable or interactive rows a visible hover/focus state.
- Keep action columns explicit; do not make the entire row clickable when that conflicts with buttons inside it.
- Provide a meaningful empty state rather than leaving an empty body.

### Search and filters

Search controls use `type="search"` and inherit the shared magnifier treatment from `modern-ui.css`.

- Use a visible `Search` label for filter panels; an accessible name is acceptable only for compact toolbars where the surrounding context is unambiguous.
- Placeholder text describes searchable fields or identifiers; it does not replace the label.
- Search runs on Enter and through a labeled Search button where practical.
- Debounce live search and do not fire a request for every keystroke.
- Pair multiple filters with Refresh or Clear/Reset behavior.
- Keep result counts and request errors outside the input so they remain readable.

Recommended structure:

```php
<label class="ui-field ui-field--search" for="record-search">
    <span>Search</span>
    <input id="record-search" type="search" placeholder="Name, ID, or reference">
</label>
```

### Dropdowns

Use a native `<select>` for ordinary fixed choices. `modern-controls.js` progressively enhances it into the NXX-style button/listbox while retaining the original select for form submission and existing JavaScript integrations.

- Every select needs a visible label.
- The first option describes the scope, such as `All Stores`, rather than `Select...` when an empty value is valid.
- Use a disabled prompt option only when selection is required.
- Option values are canonical identifiers; labels are user-facing text.
- Do not use a dropdown for destructive confirmation or a list with rich searchable records.
- For searchable people, stores, or products, use an input/listbox picker with explicit selected state instead.
- Do not change the chosen value merely because options refresh.

### Date controls

Use native `type="date"` controls for bounded business dates. `modern-controls.js` progressively enhances them into the NXX-style calendar popover while retaining the ISO `YYYY-MM-DD` input as the submitted value.

- The visible value uses `Mon D, YYYY`; storage and request values remain ISO dates.
- The calendar has previous/next month navigation, adjacent-month days, Today, and Clear.
- `min`, `max`, `disabled`, `required`, labels, and validation attributes remain attached to the native control.
- If JavaScript is unavailable, the browser-native date control remains usable.

- Use visible `From`, `To`, or business-specific labels.
- Store and transmit dates as `YYYY-MM-DD`.
- Display formatted dates only after parsing the canonical value.
- Validate that `From` is not later than `To`.
- Date ranges must have an explicit timezone/business-date interpretation when they affect reports or settlements.
- Do not prefill a historical or financial filter unless the default range is visible and expected.

### Form fields

Use `.ui-field` or the existing compatible `.field` structure.
For ordinary input controls, prefer `app/Views/components/form_field.php`; use manual markup when the control has a richer picker or composite interaction.

- Label first, control second, optional help text third, validation message last.
- Help text uses `.ui-field-help` or `.field-help`.
- Validation uses `.ui-field-error` or `.field-error` and `aria-invalid="true"` on the control.
- Required fields must be identified in text, not color alone.
- Disabled controls must explain why when the reason is not obvious.
- Keep money, quantity, PIN, and identifier input modes appropriate to their data.

### Status

Use `.status-pill` plus a semantic modifier. Status text must remain understandable without color.

Do not use ambiguous labels such as `Done` where a business state such as `Settled`, `Approved`, or `Closed` is available.

### Modals and confirmation

Use the unified modal classes in `app.css`.

- Modal overlay uses one of the supported root classes: `.admin-modal`, `.acct-modal`, `.inv-modal`, `.receipt-modal`, or `.app-modal`.
- The dialog card has a header, content body, and optional action footer.
- Provide `role="dialog"`, `aria-modal="true"`, and `aria-labelledby` pointing to the visible title. The shared layout adds missing baseline semantics to legacy modals.
- Read-only dialogs have a close control and, when useful, one close action.
- Editable dialogs use close, Cancel, and one primary action.
- Sensitive or destructive dialogs use Cancel and a clearly named destructive confirmation.
- The primary action is last in reading order.
- Disable every dismissal path while an irreversible save is actively running.
- Focus should enter the modal and return to the trigger.
- Escape and the close control should dismiss non-blocking modals.
- Clicking the backdrop may close informational dialogs, but must not dismiss a destructive confirmation accidentally.
- Destructive confirmation must name the affected record and consequence.
- On mobile, action buttons become full-width with the primary action visually prominent.
- Do not use native `alert()`, `confirm()`, or `prompt()` for product workflows.

Use `window.IbemsDialog` for lightweight confirmations, notices, and single-value prompts:

```js
const confirmed = await window.IbemsDialog.confirm(
    "This change cannot be automatically reversed.",
    {
        title: "Confirm change?",
        confirmLabel: "Continue",
        tone: "danger",
    }
);
```

The shared dialog traps keyboard focus, supports Escape for cancellable operations, restores focus to the opening control, and provides consistent validation and destructive-action styling.

Recommended anatomy:

```html
<div class="app-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
    <div class="app-modal-card">
        <div class="app-modal-head">
            <div>
                <span class="modal-eyebrow">Context</span>
                <h4 id="dialog-title">Dialog title</h4>
            </div>
            <button type="button" class="app-modal-close" aria-label="Close dialog">x</button>
        </div>

        <div><!-- Dialog content --></div>

        <div class="app-modal-actions">
            <button type="button" class="secondary-btn">Cancel</button>
            <button type="button" class="primary-btn">Save changes</button>
        </div>
    </div>
</div>
```

## Interaction States

Every data-driven section must define:

- Loading: identify what is loading and prevent duplicate submission.
- Empty: explain why there are no records and offer a relevant next action.
- Error: explain what failed and provide retry or recovery where possible.
- Success: confirm what changed without hiding important resulting state.

Use inline validation for form fields and toast messages for non-blocking page-level feedback. Do not rely on color alone.

## Formatting and Data Safety

- JavaScript money and date formatting uses `public/assets/js/ibems-format.js`.
- PHP uses the shared `ibems_*` formatting helpers where available.
- Currency labels must identify PHP consistently.
- Escape server-rendered output with `esc()` unless deliberately rendering reviewed HTML.
- JavaScript-generated markup must escape user or database values.
- Mutating requests must use the shared CSRF support.

## Responsive and Accessibility Baseline

- Core actions must remain available at 360 CSS pixels.
- Tables may scroll horizontally, but page-level horizontal scrolling is not acceptable.
- Interactive targets should be at least 40 by 40 CSS pixels, preferably 44 by 44.
- Keyboard focus must be visible.
- Inputs must have labels.
- Decorative icons use `aria-hidden="true"`.
- Current navigation uses `aria-current="page"`.
- Do not remove focus outlines without supplying a visible replacement.

## Adding a New Screen

1. Select the correct role layout.
2. Reuse the page heading, panel, table, button, status, and modal conventions.
3. Put only screen-specific rules in a page stylesheet.
4. Put screen behavior in a matching page JavaScript file.
5. Reuse CSRF and formatting helpers.
6. Check loading, empty, error, success, mobile, and keyboard states.
7. Run PHP and JavaScript syntax checks and visually smoke-test the screen.

## Review Checklist

- The screen uses the correct role layout.
- No global component is copied into page-specific CSS.
- Primary and destructive actions are visually distinct.
- Money, dates, and statuses are formatted consistently.
- Loading, empty, error, and success states exist.
- Forms and controls are keyboard accessible.
- The page works on mobile and desktop.
- No inline style or script was added without a documented reason.
