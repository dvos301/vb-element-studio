# VB Element Studio -- AI Agent Reference

VB Element Studio is a WordPress plugin that creates custom WPBakery Page Builder elements from HTML/CSS. Each element is stored as a `vb_element` custom post type with metadata for the HTML template, CSS, and editable parameters. Elements render as shortcodes in page content and appear in the WPBakery visual editor. The admin UI now supports a one-box import flow that can ingest a combined HTML/CSS snippet, suppress obvious layout wrappers, split it into candidate sections, and batch-create multiple reusable elements.

**This file is intended for AI coding agents (Claude, Codex, etc.) operating via SSH/WP-CLI.**

---

## MANDATORY: Parameterize All Editable Content

**Every element MUST define params for ALL user-facing text** — headings, descriptions, button labels, URLs, colors. Never hardcode content that a site owner would want to change. Use `{{param_name}}` placeholders in the HTML template.

**Rules:**
- Every visible text string (heading, paragraph, button, link text, alt text) MUST be a `{{param}}`.
- Every URL (`href`, `src`) that a user might change MUST be a `{{param}}`.
- Every color in inline styles or CSS that a user might customize MUST be a `{{param}}`.
- For sections with repeating items (cards, features, steps, team members), use `param_group` with `{{#items}}...{{/items}}` repeater blocks.
- Elements that still contain hardcoded user-facing content are rejected on save/import. Do not rely on warnings alone.
- Run `wp vb-element validate <slug>` if you need to inspect why a definition is being rejected.
- Use `--require-params` on `wp vb-element create` to enforce that params are always provided.
- **Never use `{{param}}` placeholders inside HTML `style` attributes.** WordPress's HTML sanitizer validates CSS values at save time. Put dynamic colors, backgrounds, and other style values in the **CSS template** instead. Example: instead of `<section style="background:{{bg_color}}">`, use a CSS rule `.my-section { background: {{bg_color}}; }`.

**Bad** — hardcoded text:
```html
<h1>Welcome to Our Platform</h1>
<p>We build amazing things.</p>
```

**Good** — fully parameterized:
```html
<h1>{{heading}}</h1>
<p>{{description}}</p>
```

---

## Quick Start

```bash
# List existing elements
wp vb-element list

# Create an element from a bundled template
wp vb-element create-from-template hero-section

# Create a custom element from files
wp vb-element create --name="My Banner" --html=@banner.html --css=@banner.css --params=@params.json

# Place an element on a page
wp vb-element place vb_my_banner --page=homepage --atts='{"heading":"Welcome"}'
```

## Admin Workflow Notes

- The default create flow is paste-first: users can paste one combined HTML/CSS snippet into the admin UI and review detected candidate sections before saving.
- Import review may create multiple elements in one pass and optionally place them onto a page in detected order.
- The importer suppresses obvious layout wrappers (`container`, `row`, `col`, etc.) and deduplicates parent/child candidates before review.
- The importer duplicates extracted CSS across detected sections during review. If the source snippet uses global selectors such as `body`, `:root`, or shared animation/font rules, expect warnings and review each candidate before saving.

---

## WP-CLI Commands

All commands live under the `vb-element` namespace.

### create

Create a new element.

```bash
wp vb-element create \
  --name="Hero Section" \
  --html=@hero.html \
  --css=@hero.css \
  --params=@params.json \
  --category="Landing Page" \
  --description="Full-width hero with CTA"
```

Flags:
- `--name` (required) -- Display name.
- `--html` -- HTML template with `{{param_name}}` placeholders. Prefix with `@` to read from file.
- `--css` -- CSS rules (auto-scoped to element wrapper). Prefix with `@` to read from file.
- `--params` -- JSON array of parameter definitions. Prefix with `@` to read from file.
- `--html-base64` -- HTML template as a base64-encoded string (avoids shell escaping issues in ephemeral SSH).
- `--css-base64` -- CSS as a base64-encoded string.
- `--params-base64` -- Params JSON as a base64-encoded string.
- `--category` -- WPBakery category grouping. Default: `VB Elements`.
- `--description` -- Short description.
- `--slug` -- Shortcode tag. Auto-generated as `vb_<name>` if omitted.
- `--icon` -- Dashicon class. Default: `dashicons-editor-code`.
- `--require-params` -- Reject creation if no `--params` provided.

Base64 flags take priority over `@file` and inline string values. Use them when shell escaping is problematic:

```bash
wp vb-element create --name="Banner" \
  --html-base64="$(printf '<div>{{heading}}</div>' | base64)" \
  --params-base64="$(printf '[{"param_name":"heading","type":"textfield","heading":"Heading","default":"Hello"}]' | base64)"
```

After creation, the CLI only succeeds if the element is fully editable. Hardcoded user-facing text, unmatched placeholders, or similar validation issues now block creation.

### list

```bash
wp vb-element list
wp vb-element list --format=json
wp vb-element list --fields=id,name,slug
```

### get

```bash
wp vb-element get vb_hero_section
wp vb-element get 42 --format=json
```

### update

Update specific fields of an existing element. Omitted fields keep their current values. Also supports `--html-base64`, `--css-base64`, `--params-base64` flags.

```bash
wp vb-element update vb_hero_section --html=@hero-v2.html --css=@hero-v2.css
wp vb-element update 42 --name="Updated Hero" --category="New Category"
```

### delete

```bash
wp vb-element delete vb_hero_section --yes
```

### export / import

```bash
wp vb-element export vb_hero_section > hero.json
wp vb-element import hero.json
```

### place

Insert an element into a page's `post_content` wrapped in `[vc_row][vc_column]...[/vc_column][/vc_row]`. Outputs the page URL on success.

```bash
wp vb-element place vb_hero_section --page=homepage
wp vb-element place vb_hero_section --page=42 --atts='{"heading":"Hello"}' --position=prepend
wp vb-element place vb_cta_banner --page=about --position=after:vb_hero_section
```

Flags:
- `--page` (required) -- Page ID, slug, or title.
- `--atts` -- JSON object of shortcode attribute overrides.
- `--position` -- `append` (default), `prepend`, or `after:<shortcode_tag>`.

### remove-from-page

Remove an element's shortcode (and its `[vc_row]` wrapper) from a page. Works by searching `post_content` for the shortcode tag — the element post does **not** need to exist, so you can clean up orphaned shortcodes after deleting an element.

```bash
wp vb-element remove-from-page vb_hero_section --page=homepage
wp vb-element remove-from-page vb_cta_banner --page=42 --occurrence=2
```

Flags:
- `--page` (required) -- Page ID, slug, or title.
- `--occurrence` -- Which occurrence to remove if the element appears multiple times. Default: 1.

### preview

Render an element to HTML via CLI for debugging — no browser needed.

```bash
wp vb-element preview vb_hero_section
wp vb-element preview vb_hero_section --atts='{"heading":"Hello World"}'
```

Outputs the fully rendered HTML (with `<style>` block and wrapper div) to stdout. Uses default param values unless overridden with `--atts`.

### templates

List bundled starter templates.

```bash
wp vb-element templates
wp vb-element templates --format=json
```

### create-from-template

```bash
wp vb-element create-from-template hero-section
wp vb-element create-from-template hero-section --name="Custom Hero" --category="My Elements"
```

Flags: `--name`, `--category`, `--override-defaults` (JSON object of param_name → new default value).

Override template defaults without writing full HTML/CSS from scratch:

```bash
wp vb-element create-from-template benefits-cards \
  --name="Dental Benefits" \
  --override-defaults='{"heading":"Our Dental Benefits","subheading":"We care about your smile"}'
```

### create-batch

Create multiple elements from a single JSON file containing an array of element definitions.

```bash
wp vb-element create-batch elements.json
cat elements.json | wp vb-element create-batch -
wp vb-element create-batch elements.json --require-params
```

The JSON file is an array where each entry has the same keys as `create` flags (`name`, `html`, `css`, `params`, etc.):

```json
[
    {
        "name": "Hero Section",
        "html": "<section class=\"hero\"><h1>{{heading}}</h1></section>",
        "css": ".hero { padding: 80px 0; }",
        "params": [{"param_name": "heading", "type": "textfield", "heading": "Heading", "default": "Welcome"}]
    },
    {
        "name": "CTA Banner",
        "html": "<div class=\"cta\"><h2>{{title}}</h2><a href=\"{{url}}\">{{button}}</a></div>",
        "css": ".cta { text-align: center; }",
        "params": [
            {"param_name": "title", "type": "textfield", "heading": "Title", "default": "Get Started"},
            {"param_name": "button", "type": "textfield", "heading": "Button Text", "default": "Sign Up"},
            {"param_name": "url", "type": "textfield", "heading": "Button URL", "default": "#"}
        ]
    }
]
```

### place-batch

Place multiple elements on a page in a single `post_content` update, preserving order. Accepts `--elements` (JSON string) or `--elements-base64` (base64-encoded JSON, avoids SSH quoting issues).

```bash
wp vb-element place-batch --page=42 --elements='["vb_hero","vb_benefits","vb_cta"]'
# SSH-safe alternative:
wp vb-element place-batch --page=42 --elements-base64="$(echo '["vb_hero","vb_cta"]' | base64)"
```

Each entry in the `--elements` array can be a string (slug only) or an object with custom attributes:

```bash
wp vb-element place-batch --page=homepage --elements='[
    {"slug":"vb_hero_section","atts":{"heading":"Welcome"}},
    "vb_features_grid",
    {"slug":"vb_cta_banner","atts":{"title":"Get Started"}}
]'
```

Flags: `--page` (required), `--elements` (required, JSON array), `--position` (`append`|`prepend`).

### validate

Scan an element's HTML template for hardcoded text that should be parameterized.

```bash
wp vb-element validate vb_hero_section
wp vb-element validate 42 --format=json
```

This command checks for:
- Text nodes longer than 3 words without `{{param}}` placeholders
- Hardcoded attribute values (alt, title, placeholder, aria-label)
- Missing parameter definitions

---

## Element Data Model

Each element is a `vb_element` post with these meta fields:

| Meta Key | Type | Description |
|---|---|---|
| `_vb_base_tag` | string | Shortcode tag, e.g. `vb_hero_section` |
| `_vb_description` | string | Element description |
| `_vb_icon` | string | Dashicon class |
| `_vb_category` | string | WPBakery category |
| `_vb_raw_html` | string | Original HTML |
| `_vb_raw_css` | string | Original CSS |
| `_vb_html_template` | string | HTML with `{{param_name}}` placeholders |
| `_vb_scoped_css` | string | CSS with selectors prefixed by `#<scope_id>` |
| `_vb_scope_id` | string | Unique wrapper ID, e.g. `vb-el-a3f9b2` |
| `_vb_params` | JSON string | Array of parameter definition objects |
| `_vb_sanitization_notes` | JSON string | Notes from input sanitization |

---

## Parameter Schema

Each parameter in the `_vb_params` JSON array has this structure:

```json
{
    "param_name": "heading",
    "type": "textfield",
    "heading": "Main Heading",
    "description": "The primary heading text",
    "default": "Welcome to our site"
}
```

### Supported Types

| Type | WPBakery Control | Notes |
|---|---|---|
| `textfield` | Single-line text input | Use for headings, labels, URLs |
| `textarea` | Multi-line text input | Use for paragraphs, descriptions |
| `colorpicker` | Color picker | Returns hex color, e.g. `#e94560` |
| `attach_image` | Media library picker | Returns attachment ID or URL |
| `dropdown` | Select dropdown | Requires `options` field (comma-separated) |
| `checkbox` | Checkbox | Value is `true` or `false` |
| `param_group` | Repeatable group | For lists of items (cards, steps, team). Has nested `params`. |

### Dropdown Example

```json
{
    "param_name": "layout",
    "type": "dropdown",
    "heading": "Layout Style",
    "options": "left,center,right",
    "default": "center"
}
```

### param_group Example (Repeater)

Use `param_group` for sections with a dynamic number of items (cards, steps, team members, etc.).

**Parameter definition:**

```json
{
    "param_name": "items",
    "type": "param_group",
    "heading": "Cards",
    "default": [
        {"icon": "⚡", "title": "Fast", "description": "Lightning-fast performance."},
        {"icon": "🔒", "title": "Secure", "description": "Enterprise-grade security."}
    ],
    "params": [
        {"param_name": "icon", "type": "textfield", "heading": "Icon", "default": "⭐"},
        {"param_name": "title", "type": "textfield", "heading": "Title", "default": "Feature"},
        {"param_name": "description", "type": "textarea", "heading": "Description", "default": "Description text."}
    ]
}
```

**HTML template with repeater block:**

```html
<section class="benefits">
    <h2>{{heading}}</h2>
    <div class="grid">
        {{#items}}
        <div class="card">
            <span class="icon">{{icon}}</span>
            <h3>{{title}}</h3>
            <p>{{description}}</p>
        </div>
        {{/items}}
    </div>
</section>
```

`{{#items}}...{{/items}}` loops over each item in the group. Inside the block, use `{{field_name}}` for the sub-param fields.

**Placing with repeater data via CLI:**

```bash
wp vb-element place vb_benefits_cards --page=homepage --atts='{"heading":"Why Choose Us","items":[{"icon":"⚡","title":"Fast","description":"Speed matters."}]}'
```

---

## HTML Template Format

Templates use `{{param_name}}` as placeholders for single-value params, and `{{#param_name}}...{{/param_name}}` blocks for repeatable param_groups.

```html
<section class="hero">
    <h1>{{heading}}</h1>
    <p>{{subtitle}}</p>
    <a href="{{button_url}}" class="btn">{{button_text}}</a>
</section>
```

Rules:
- Use `{{param_name}}` tokens matching the `param_name` field in params.
- Use `{{#group_name}}...{{/group_name}}` for repeater blocks (param_group type).
- Do NOT include `<script>` tags, inline event handlers (`onclick`, etc.), or `<link>` stylesheet tags.
- Google Fonts `<link>` tags are extracted and moved to `<head>` automatically.
- CSS should be provided separately (not inline `<style>` blocks).
- Use class-based selectors. CSS is automatically scoped to the element wrapper.
- NEVER hardcode user-facing text. Every visible string must be a `{{param}}`.

---

## Shortcode Format in Pages

WPBakery stores page content as nested shortcodes. Every element must be inside a `[vc_row][vc_column]` wrapper:

```
[vc_row][vc_column][vb_hero_section heading="Hello World" subtitle="Welcome"][/vc_column][/vc_row]
[vc_row][vc_column][vb_features_grid heading="Our Features"][/vc_column][/vc_row]
```

The `place` command handles this wrapping automatically. If editing `post_content` manually, always wrap elements in `[vc_row][vc_column]...[/vc_column][/vc_row]`.

Self-closing shortcodes (no inner content) do not need `[/shortcode_tag]`.

---

## Bundled Templates

Located in the `templates/` directory as JSON files. See `templates/_template-schema.json` for the full schema.

| Template | Description |
|---|---|
| `hero-section` | Full-width hero with heading, subtitle, CTA button |
| `features-grid` | Three-column feature grid |
| `testimonial-card` | Quote block with author attribution |
| `cta-banner` | Horizontal call-to-action banner |
| `pricing-table` | Single pricing tier card |
| `faq-accordion` | Collapsible FAQ with details/summary |
| `stats-counter` | Row of four stat counters |
| `team-member` | Team member card with photo and bio |
| `benefits-cards` | Repeatable card grid using param_group (best example of repeater pattern) |

Templates are a great reference for the expected JSON structure when creating custom elements. **The `benefits-cards` template is the canonical example of how to use `param_group` repeaters.**

---

## Common Workflows

### Build a landing page from scratch

```bash
# 1. Create elements from templates
wp vb-element create-from-template hero-section
wp vb-element create-from-template features-grid
wp vb-element create-from-template testimonial-card
wp vb-element create-from-template cta-banner

# 2. Create the page
wp post create --post_type=page --post_title="Landing Page" --post_status=publish --porcelain
# (returns page ID, e.g. 99)

# 3. Place all elements in one call (fastest)
wp vb-element place-batch --page=99 --elements='[
    {"slug":"vb_hero_section","atts":{"heading":"Welcome","subtitle":"Build your dream site"}},
    "vb_features_grid",
    "vb_testimonial_card",
    "vb_cta_banner"
]'
```

### Create a fully custom element

```bash
# 1. Write the HTML template to a file
cat > /tmp/banner.html << 'HTML'
<div class="custom-banner">
    <h2>{{heading}}</h2>
    <p>{{message}}</p>
</div>
HTML

# 2. Write the CSS to a file
cat > /tmp/banner.css << 'CSS'
.custom-banner {
    background: {{bg_color}};
    color: #fff;
    padding: 40px;
    text-align: center;
    border-radius: 8px;
}
.custom-banner h2 {
    font-size: 2rem;
    margin: 0 0 12px;
}
.custom-banner p {
    font-size: 1.1rem;
    opacity: 0.9;
    margin: 0;
}
CSS

# 3. Write the params to a file
cat > /tmp/banner-params.json << 'JSON'
[
    {"param_name": "heading", "type": "textfield", "heading": "Heading", "default": "Hello World"},
    {"param_name": "message", "type": "textarea", "heading": "Message", "default": "Welcome to our site."},
    {"param_name": "bg_color", "type": "colorpicker", "heading": "Background Color", "default": "#4361ee"}
]
JSON

# 4. Create the element
wp vb-element create --name="Custom Banner" --html=@/tmp/banner.html --css=@/tmp/banner.css --params=@/tmp/banner-params.json --category="Custom"

# 5. Place on a page
wp vb-element place vb_custom_banner --page=homepage --atts='{"heading":"Special Offer","bg_color":"#e94560"}'
```

### Update an existing element's HTML

```bash
# Write updated HTML to a file
cat > /tmp/hero-v2.html << 'HTML'
<section class="vbes-hero">
    <div class="vbes-hero__inner">
        <span class="vbes-hero__badge">{{badge_text}}</span>
        <h1 class="vbes-hero__heading">{{heading}}</h1>
        <p class="vbes-hero__subtitle">{{subtitle}}</p>
        <a href="{{button_url}}" class="vbes-hero__btn">{{button_text}}</a>
    </div>
</section>
HTML

# Update the element (only HTML changes; other fields are preserved)
wp vb-element update vb_hero_section --html=@/tmp/hero-v2.html
```

### Export, modify, and re-import

```bash
wp vb-element export vb_hero_section > hero-backup.json
# Edit hero-backup.json as needed...
wp vb-element import hero-backup.json
```

---

## PHP API (for plugin/theme code)

All methods are static on `VB_ES_API`:

```php
VB_ES_API::create_element( [ 'name' => '...', 'html' => '...', 'css' => '...', 'params' => [...] ] );
VB_ES_API::update_element( 'vb_hero_section', [ 'html' => '...' ] );
VB_ES_API::delete_element( 'vb_hero_section' );
VB_ES_API::get_element( 'vb_hero_section' );       // returns array or null
VB_ES_API::list_elements();                          // returns array of element arrays
VB_ES_API::export_element( 'vb_hero_section' );     // portable array
VB_ES_API::import_element( $json_string_or_array );
VB_ES_API::place_on_page( $page_id, 'vb_hero_section', ['heading' => 'Hi'], 'append' );
VB_ES_API::get_templates();
VB_ES_API::get_template( 'hero-section' );
VB_ES_API::create_from_template( 'hero-section', ['name' => 'Custom Name'] );
VB_ES_API::create_from_template( 'benefits-cards', ['override_defaults' => ['heading' => 'New']] );
VB_ES_API::create_batch( [ [...element1...], [...element2...] ] );   // batch create
VB_ES_API::place_batch( $page_id, ['vb_hero', 'vb_cta'], 'append' );// batch place, returns URL
VB_ES_API::remove_from_page( $page_id, 'vb_hero_section' );         // remove from page, returns URL
VB_ES_API::preview_element( 'vb_hero_section', ['heading' => 'Hi'] ); // render to HTML string
VB_ES_API::validate_element( 'vb_hero_section' );   // warnings: hardcoded text + CSS placeholders
```

---

## File Structure

```
vb-element-studio/
├── vb-element-studio.php           # Plugin bootstrap
├── AGENTS.md                       # This file (AI agent reference)
├── README.md                       # Human-readable docs
├── includes/
│   ├── class-element-manager.php   # CPT registration, CRUD, sanitization
│   ├── class-api.php               # Programmatic API (static methods)
│   ├── class-cli.php               # WP-CLI commands (loaded only when WP_CLI defined)
│   ├── class-ai-detector.php       # AI-powered parameter detection (admin UI)
│   ├── class-css-scoper.php        # Prefixes CSS selectors with #scope_id
│   ├── class-shortcode-handler.php # Frontend shortcode rendering
│   └── class-vc-registrar.php      # Registers elements with WPBakery via vc_map()
├── admin/
│   ├── class-admin-page.php        # Admin menu, forms, views
│   └── views/
│       ├── list-elements.php       # Element listing table
│       ├── edit-element.php        # Create/edit form
│       └── settings.php            # Plugin settings
├── assets/
│   ├── admin.js                    # Admin JavaScript
│   └── admin.css                   # Admin styles
└── templates/
    ├── _template-schema.json       # JSON Schema for template format
    ├── hero-section.json           # Bundled templates...
    ├── features-grid.json
    ├── testimonial-card.json
    ├── cta-banner.json
    ├── pricing-table.json
    ├── faq-accordion.json
    ├── stats-counter.json
    └── team-member.json
```
