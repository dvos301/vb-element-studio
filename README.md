# VB Element Studio

**Version:** 1.2.0
**Requires:** WordPress 5.0+, PHP 7.4+, WPBakery Page Builder
**License:** GPL-2.0-or-later

Create custom WPBakery Page Builder elements from AI-generated HTML and CSS — without writing PHP. Paste a component, auto-detect editable parameters with one click, and register it as a fully functional WPBakery element.

---

## What It Does

VB Element Studio bridges the gap between AI-generated designs and WordPress page building. Instead of manually coding WPBakery elements with `vc_map()`, shortcode handlers, and parameter arrays, you paste HTML/CSS and the plugin does the rest.

### The Workflow

1. **Generate** a UI component (hero section, card grid, CTA banner, etc.) using an AI tool like Claude
2. **Paste** the HTML and CSS into VB Element Studio's admin editor
3. **Click "Auto-detect Parameters"** — the plugin calls your configured AI provider/model (Anthropic, OpenAI, or Gemini) to analyse the HTML and identify editable parts (headings, descriptions, button text, link URLs, colors)
4. **Review** the suggested parameters, adjust or add your own
5. **Save** — the plugin registers a new WPBakery element with a unique shortcode
6. **Use it** — the element appears in WPBakery's element library, fully editable on any page

### What Gets Created

Each saved element produces:
- A **WPBakery element** that appears in the "Add Element" picker
- A **shortcode** (e.g. `[vb_benefits_grid]`) with editable attributes
- **Scoped CSS** that won't conflict with your theme or other elements
- A **settings panel** in WPBakery with all defined parameters (text fields, color pickers, image selectors, dropdowns, etc.)

---

## IMPORTANT: Parameterize All Content

**Every element MUST define params for ALL user-facing text** — headings, descriptions, button labels, URLs, colors. Never hardcode content. Use `{{param_name}}` placeholders in HTML. For repeating items (cards, features, steps), use `param_group` with `{{#items}}...{{/items}}` repeater blocks. After creating any element, run `wp vb-element validate <slug>` to check for missed hardcoded text.

---

## WP-CLI Commands (AI Agent / SSH Reference)

All elements can be managed from the command line via WP-CLI. This is the primary interface for AI agents operating over SSH.

### Quick Start

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

### Command Reference

**create** -- Create a new element.

```bash
wp vb-element create \
  --name="Hero Section" \
  --html=@hero.html \
  --css=@hero.css \
  --params=@params.json \
  --category="Landing Page" \
  --description="Full-width hero with CTA"
```

Flags: `--name` (required), `--html`, `--css`, `--params` (all accept `@filename` to read from file), `--slug`, `--category`, `--description`, `--icon`, `--require-params` (reject if no params provided). Post-creation validation automatically warns about hardcoded text.

**list** -- List all elements.

```bash
wp vb-element list
wp vb-element list --format=json
wp vb-element list --fields=id,name,slug
```

**get** -- Get a single element's details.

```bash
wp vb-element get vb_hero_section
wp vb-element get 42 --format=json
```

**update** -- Update specific fields (omitted fields keep current values).

```bash
wp vb-element update vb_hero_section --html=@hero-v2.html --css=@hero-v2.css
wp vb-element update 42 --name="Updated Hero"
```

**delete** -- Delete an element.

```bash
wp vb-element delete vb_hero_section --yes
```

**export / import** -- Portable JSON format.

```bash
wp vb-element export vb_hero_section > hero.json
wp vb-element import hero.json
```

**place** -- Insert an element into a page wrapped in `[vc_row][vc_column]...[/vc_column][/vc_row]`.

```bash
wp vb-element place vb_hero_section --page=homepage
wp vb-element place vb_hero_section --page=42 --atts='{"heading":"Hello"}' --position=prepend
wp vb-element place vb_cta_banner --page=about --position=after:vb_hero_section
```

Flags: `--page` (required, accepts ID/slug/title), `--atts` (JSON object), `--position` (`append`|`prepend`|`after:<tag>`).

**templates** -- List bundled starter templates.

```bash
wp vb-element templates
```

**create-from-template** -- Create an element from a bundled template.

```bash
wp vb-element create-from-template hero-section
wp vb-element create-from-template hero-section --name="Custom Hero"
```

**validate** -- Scan an element for hardcoded text that should be parameterized.

```bash
wp vb-element validate vb_hero_section
wp vb-element validate 42 --format=json
```

### Bundled Templates

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
| `benefits-cards` | Repeatable card grid using param_group (best repeater example) |

### HTML Template Format

Templates use `{{param_name}}` placeholders that get replaced with parameter values at render time:

```html
<section class="hero">
    <h1>{{heading}}</h1>
    <p>{{subtitle}}</p>
    <a href="{{button_url}}" class="btn">{{button_text}}</a>
</section>
```

### Parameter JSON Format

```json
[
    {"param_name": "heading", "type": "textfield", "heading": "Heading", "description": "Main heading text", "default": "Welcome"},
    {"param_name": "subtitle", "type": "textarea", "heading": "Subtitle", "default": "Supporting text"},
    {"param_name": "bg_color", "type": "colorpicker", "heading": "Background Color", "default": "#1a1a2e"},
    {"param_name": "layout", "type": "dropdown", "heading": "Layout", "options": "left,center,right", "default": "center"}
]
```

### Repeater / param_group (Multi-Card Sections)

For sections with a variable number of items (cards, steps, team members), use `param_group`:

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
        {"param_name": "description", "type": "textarea", "heading": "Description", "default": "Description."}
    ]
}
```

In the HTML template, use `{{#items}}...{{/items}}` to loop over items:

```html
<div class="grid">
    {{#items}}
    <div class="card">
        <span>{{icon}}</span>
        <h3>{{title}}</h3>
        <p>{{description}}</p>
    </div>
    {{/items}}
</div>
```

See the `benefits-cards` template for a complete working example.

### Shortcode Format in Pages

WPBakery stores page content as nested shortcodes. Every element must be inside a `[vc_row][vc_column]` wrapper:

```
[vc_row][vc_column][vb_hero_section heading="Hello World" subtitle="Welcome"][/vc_column][/vc_row]
[vc_row][vc_column][vb_features_grid heading="Our Features"][/vc_column][/vc_row]
```

The `place` command handles this wrapping automatically.

### Common Workflow: Build a Landing Page

```bash
# 1. Create elements from templates
wp vb-element create-from-template hero-section
wp vb-element create-from-template features-grid
wp vb-element create-from-template cta-banner

# 2. Create the page
wp post create --post_type=page --post_title="Landing Page" --post_status=publish --porcelain

# 3. Place elements on the page (uses the returned page ID)
wp vb-element place vb_hero_section --page=99 --atts='{"heading":"Welcome"}'
wp vb-element place vb_features_grid --page=99
wp vb-element place vb_cta_banner --page=99
```

### Common Workflow: Custom Element from Scratch

```bash
# Write HTML template to a file
cat > /tmp/banner.html << 'HTML'
<div class="custom-banner">
    <h2>{{heading}}</h2>
    <p>{{message}}</p>
</div>
HTML

# Write CSS to a file
cat > /tmp/banner.css << 'CSS'
.custom-banner { background: {{bg_color}}; color: #fff; padding: 40px; text-align: center; border-radius: 8px; }
.custom-banner h2 { font-size: 2rem; margin: 0 0 12px; }
.custom-banner p { font-size: 1.1rem; opacity: 0.9; margin: 0; }
CSS

# Write params to a file
cat > /tmp/banner-params.json << 'JSON'
[
    {"param_name": "heading", "type": "textfield", "heading": "Heading", "default": "Hello World"},
    {"param_name": "message", "type": "textarea", "heading": "Message", "default": "Welcome to our site."},
    {"param_name": "bg_color", "type": "colorpicker", "heading": "Background Color", "default": "#4361ee"}
]
JSON

# Create it
wp vb-element create --name="Custom Banner" --html=@/tmp/banner.html --css=@/tmp/banner.css --params=@/tmp/banner-params.json

# Place on a page
wp vb-element place vb_custom_banner --page=homepage
```

### PHP API

All methods are static on `VB_ES_API`:

```php
VB_ES_API::create_element( [ 'name' => '...', 'html' => '...', 'css' => '...', 'params' => [...] ] );
VB_ES_API::update_element( 'vb_hero_section', [ 'html' => '...' ] );
VB_ES_API::delete_element( 'vb_hero_section' );
VB_ES_API::get_element( 'vb_hero_section' );
VB_ES_API::list_elements();
VB_ES_API::export_element( 'vb_hero_section' );
VB_ES_API::import_element( $json_string_or_array );
VB_ES_API::place_on_page( $page_id, 'vb_hero_section', ['heading' => 'Hi'], 'append' );
VB_ES_API::get_templates();
VB_ES_API::create_from_template( 'hero-section', ['name' => 'Custom Name'] );
VB_ES_API::validate_element( 'vb_hero_section' );   // check for hardcoded text
```

---

## Installation

1. Download `vb-element-studio.zip`
2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**
3. Upload the zip and activate
4. WPBakery Page Builder must be active — the plugin shows a warning if it's not detected

### Configuration

Go to **VB Element Studio > Settings** and configure:

| Setting | Description |
|---|---|
| **AI Provider + Model** | Choose Anthropic, OpenAI, or Gemini and select a model preset (or set a custom model ID) |
| **Provider API Keys** | Add the API key(s) for the providers you want to use for Auto-detect Parameters |
| **Default WPBakery Category** | The category name your elements appear under in WPBakery (default: "VB Elements") |
| **Allow Unfiltered HTML** | Bypasses HTML sanitisation on output. Enable if your templates use advanced HTML that WordPress strips by default |

---

## Admin Pages

### All Elements

Lists every custom element you've created. Shows the element name, shortcode tag, parameter count, and edit/delete actions.

### Add New / Edit Element

The main editor with five sections:

**Section 1 — Element Info**
Name, shortcode slug (auto-prefixed with `vb_`), description, and WPBakery category.

**Section 2 — Paste HTML**
Paste the raw HTML for your component. This is the original, unmodified code as generated by AI.

**Section 3 — Paste CSS**
Paste the component's CSS. On save, it's automatically scoped to a unique wrapper ID so it won't affect anything else on the page.

**Section 4 — Auto-detect Parameters**
Click the button to send the HTML and CSS to the configured AI provider/model. It analyses the component and returns a tokenised HTML template with `{{param_name}}` placeholders, plus a list of suggested parameters. Results populate Section 5 automatically.

**Section 5 — HTML Template + Parameters**
The tokenised template (editable) and the parameter builder. Each parameter has:
- **Param Name** — snake_case slug used in the template token and shortcode attribute
- **Label** — human-readable name shown in WPBakery
- **Type** — textfield, textarea, colorpicker, attach_image, dropdown, or checkbox
- **Default Value** — pre-filled when the element is first added to a page
- **Description** — help text shown below the field in WPBakery

### Settings

AI provider/model selection, provider API keys, and global plugin options.

---

## Supported Parameter Types

| Type | WPBakery Control | Use For |
|---|---|---|
| `textfield` | Single-line text input | Headings, button text, short strings |
| `textarea` | Multi-line text input | Descriptions, paragraphs |
| `colorpicker` | Color wheel / hex input | Brand colors, accent colors |
| `attach_image` | WordPress media library picker | Hero images, icons, logos |
| `dropdown` | Select menu | Style variants, layout options |
| `checkbox` | Toggle (true/false) | Show/hide sections, enable features |

Every element also gets two standard WPBakery parameters appended automatically:
- **CSS Animation** — entrance animation (fade, slide, bounce, etc.)
- **Extra Class Name** — additional CSS class on the wrapper div

---

## Architecture

### File Structure

```
vb-element-studio/
├── vb-element-studio.php             # Plugin bootstrap, activation hooks, dependency check
├── AGENTS.md                         # AI agent reference (also in this README above)
├── includes/
│   ├── class-element-manager.php     # Custom post type registration + CRUD operations
│   ├── class-api.php                 # Programmatic PHP API (static methods)
│   ├── class-cli.php                 # WP-CLI commands (loaded only when WP_CLI defined)
│   ├── class-ai-detector.php         # Multi-provider AI integration + AJAX handler
│   ├── class-css-scoper.php          # CSS scoping engine
│   ├── class-shortcode-handler.php   # Frontend shortcode rendering
│   └── class-vc-registrar.php        # WPBakery vc_map() registration
├── admin/
│   ├── class-admin-page.php          # Admin menu, routing, form handlers
│   └── views/
│       ├── list-elements.php         # Element list table
│       ├── edit-element.php          # Create/edit element form
│       └── settings.php             # Plugin settings
├── assets/
│   ├── admin.js                      # Parameter builder UI + AI detection
│   └── admin.css                     # Admin page styles
└── templates/                        # Bundled element templates (JSON)
    ├── _template-schema.json         # JSON Schema for template format
    ├── hero-section.json
    ├── features-grid.json
    ├── testimonial-card.json
    ├── cta-banner.json
    ├── pricing-table.json
    ├── faq-accordion.json
    ├── stats-counter.json
    └── team-member.json
```

### Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│                        ADMIN (Create/Edit)                          │
│                                                                     │
│  Paste HTML/CSS ──► Auto-detect (AI provider API) ──► Review Params │
│                                                          │          │
│                                                       [Save]        │
│                                                          │          │
│                                                          ▼          │
│                                                   Element Manager   │
│                                                     │         │     │
│                                              Store CPT    Scope CSS │
│                                              + Meta       (Scoper)  │
└──────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────────┐
│                        INIT (on every page load)                     │
│                                                                      │
│  VC Registrar ──► vc_map() for each element ──► WPBakery library     │
│  Shortcode Handler ──► add_shortcode() for each element              │
└──────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────────┐
│                        FRONTEND (page render)                        │
│                                                                      │
│  Shortcode fires ──► Load template + params ──► Replace tokens       │
│                  ──► Inject scoped <style> ──► Output wrapped HTML    │
└──────────────────────────────────────────────────────────────────────┘
```

### Core Classes

**`VB_ES_Element_Manager`** — Central data layer. Registers the `vb_element` custom post type (private, not shown in wp-admin post editor). Provides `save_element()`, `get_element()`, `delete_element()`, `get_all_elements()`, and `get_element_by_slug()`. On save, generates a unique scope ID, triggers CSS scoping, and stores all metadata. Also provides `allowed_html()` which extends WordPress's default allowed tags with full SVG support.

**`VB_ES_AI_Detector`** — Handles AI integration for Anthropic, OpenAI, and Gemini. Registers the `vb_es_detect_params` AJAX action. Sends HTML + CSS to the configured model with a structured system prompt that instructs the model to return tokenised HTML and a parameter list as JSON. Includes `safe_json_decode()` with a 3-tier fallback for handling control characters in LLM-generated JSON.

**`VB_ES_CSS_Scoper`** — Parses raw CSS and prefixes every selector with a unique ID (`#vb-el-a3f9b2`). Handles `@media` queries (scopes rules inside them), `@keyframes` (passes through unmodified), and strips `html`/`body`/`:root` prefixes from selectors. Uses a character-by-character tokenizer with brace-depth tracking and string-aware matching.

**`VB_ES_Shortcode_Handler`** — Registers a WordPress shortcode for every published element. On render: loads the template, merges shortcode attributes with parameter defaults, replaces `{{token}}` placeholders with sanitised values (per-type: `sanitize_text_field`, `sanitize_hex_color`, `wp_get_attachment_url`, etc.), wraps output in a scoped `<div>`, and injects the scoped CSS as an inline `<style>` block. Deduplicates CSS if the same element appears multiple times on a page.

**`VB_ES_VC_Registrar`** — Hooks into `vc_before_init` and calls `vc_map()` for each published element. Converts the stored parameter JSON into WPBakery's param format, including dropdown option arrays and checkbox values. Appends standard `css_animation` and `el_class` params to every element.

**`VB_ES_Admin_Page`** — Registers the admin menu (top-level "VB Element Studio" with sub-pages). Handles form submissions for settings save, element save, and element delete — all nonce-protected. Enqueues admin JS/CSS only on plugin pages. Passes localised data (AJAX URL, nonce) to the frontend script.

### Storage Schema

Each element is a `vb_element` custom post type with the following meta:

| Meta Key | Content |
|---|---|
| `_vb_base_tag` | Shortcode tag (e.g. `vb_benefits_grid`) |
| `_vb_description` | Element description shown in WPBakery |
| `_vb_icon` | Icon class or URL |
| `_vb_category` | WPBakery category string |
| `_vb_raw_html` | Original HTML as pasted |
| `_vb_raw_css` | Original CSS as pasted |
| `_vb_html_template` | Tokenised HTML with `{{param}}` placeholders |
| `_vb_scoped_css` | CSS with selectors prefixed by scope ID |
| `_vb_scope_id` | Unique wrapper ID (e.g. `vb-el-a3f9b2`) |
| `_vb_params` | JSON array of parameter definitions |

### Security Model

- All admin forms use `wp_nonce_field()` / `wp_verify_nonce()`
- All AJAX requests use `check_ajax_referer()` + `current_user_can('manage_options')`
- Provider API keys are stored in `wp_options`, never exposed to frontend JavaScript
- Shortcode output is sanitised with `wp_kses()` using an extended allowed-tags list (includes SVG elements)
- Parameter values are sanitised per-type on render (text fields, hex colors, attachment IDs, etc.)
- The "Allow Unfiltered HTML" setting must be explicitly enabled to bypass output sanitisation

---

## Best Practices

### One element per section, not per page
Create separate elements for each section of a page (hero, features, testimonials, CTA). This keeps parameters manageable, makes sections reusable across pages, and lets you rearrange them in WPBakery's drag-and-drop editor.

### Use inline SVGs for icons
Inline SVGs are fully self-contained in the template, scale perfectly, and don't depend on external icon libraries. They're the most reliable icon approach for generated components.

### Review AI-detected parameters before saving
The auto-detection is a starting point. Remove parameters that don't need to be editable, add ones the AI missed, and ensure default values match your design.

### Keep CSS component-scoped
The plugin automatically scopes CSS, but avoid overly generic selectors like `h2 { }` or `p { }` in your component CSS. Use class-based selectors (`.benefits-header h2`) for predictable scoping results.

---

## Requirements

- **WordPress** 5.0 or higher
- **PHP** 7.4 or higher
- **WPBakery Page Builder** must be installed and active
- **At least one provider API key** (Anthropic, OpenAI, or Gemini) required for auto-detect feature (element creation works without it — you just define parameters manually)
