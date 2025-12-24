# BP Verbruikregistratie - Development Documentation

**Last Updated:** 2024-12-24
**Organization:** Wildopvang de Bonte Piet
**Module Location:** `/custom/bpverbruik/`
**Purpose:** Consumption registration system for tracking product usage from warehouses

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Implementation Status](#implementation-status)
3. [File Structure](#file-structure)
4. [Database Schema](#database-schema)
5. [Key Features](#key-features)
6. [Technical Architecture](#technical-architecture)
7. [Important Code Patterns](#important-code-patterns)
8. [Configuration](#configuration)
9. [Future Enhancements](#future-enhancements)
10. [Development Notes](#development-notes)

---

## Project Overview

### Purpose
The BP Verbruikregistratie module allows warehouse employees to register product consumption directly when items are taken from stock. It provides:
- Simple consumption registration form
- Automatic stock deduction
- Reporting with PDF/Excel export
- Multi-warehouse support with product filtering

### Key Problems Solved
1. **Warehouse/product mismatches** - Products are filtered by selected warehouse
2. **Manual data entry errors** - Quick quantity buttons and stock visibility
3. **Stock tracking** - Direct integration with Dolibarr stock movements
4. **Reporting** - Comprehensive consumption reports with value calculations

### User Workflow
```
1. Select Warehouse (Verbruikslocatie)
   ↓
2. Product dropdown auto-updates with warehouse products + stock levels
   ↓
3. Select Product (shows "Product Name (15x)" format)
   ↓
4. Enter Quantity (default 0, with [-][+] and quick buttons [1][2][3][4][5][10])
   ↓
5. Submit → Stock deducted, record created, form clears
```

---

## Implementation Status

### ✅ Phase 1: Core Functionality (COMPLETED)
- [x] Fixed critical bugs in verbruiken.class.php (date typo, swapped labels)
- [x] Replaced broken Manufacturing Order logic with direct stock deduction
- [x] Enabled permission checks and UI buttons
- [x] Enhanced list view with total value column
- [x] Created comprehensive report page (verbruiken_report.php)
- [x] PDF export functionality
- [x] Excel export functionality
- [x] Dutch and English translations
- [x] Access control for reports

### ✅ Phase 2: UI/UX Enhancements (COMPLETED)
- [x] Reordered fields: Warehouse → Product → Quantity
- [x] Dynamic product filtering by warehouse (AJAX)
- [x] Stock quantities displayed in product dropdown
- [x] Dutch labels (Hoeveelheid, Verbruikslocatie, etc.)
- [x] Quick quantity buttons [1][2][3][4][5][10]
- [x] Plus/minus increment buttons [-][+]
- [x] Default quantity value = 0
- [x] No default warehouse selection
- [x] Select2 dropdown support
- [x] Product field disabled until warehouse selected

### 🔮 Future Enhancements (OPTIONAL)
- [ ] Low stock visual warnings (red text for stock < 5)
- [ ] Remember last warehouse per user
- [ ] Disable/hide products with 0 stock
- [ ] Keyboard shortcuts (press "1" to set qty=1)
- [ ] Batch/lot number selection for tracked products
- [ ] Dashboard widget for recent consumptions
- [ ] Email notifications for low stock levels

---

## File Structure

```
/custom/bpverbruik/
├── CLAUDE.md                          # This documentation file
├── ajax/
│   └── get_products_by_warehouse.php  # AJAX endpoint for product filtering
├── class/
│   └── verbruiken.class.php           # Main data model (CommonObject)
├── core/
│   └── modules/
│       └── modBpVerbruik.class.php    # Module descriptor
├── langs/
│   ├── en_US/
│   │   └── bpverbruik.lang            # English translations
│   └── nl_NL/
│       └── bpverbruik.lang            # Dutch translations
├── lib/
│   └── bpverbruik_verbruiken.lib.php  # Helper functions
├── sql/
│   ├── llx_bpverbruik_verbruiken.sql  # Table creation
│   └── llx_bpverbruik_verbruiken.key.sql # Indexes and foreign keys
├── admin/
│   └── setup.php                      # Module configuration page
├── verbruiken_card.php                # Create/edit consumption form
├── verbruiken_list.php                # List view of consumptions
└── verbruiken_report.php              # Reporting page with exports
```

---

## Database Schema

### Main Table: `llx_bpverbruik_verbruiken`

```sql
CREATE TABLE llx_bpverbruik_verbruiken (
    rowid           INT AUTO_INCREMENT PRIMARY KEY,
    ref             VARCHAR(128) NOT NULL,
    label           VARCHAR(255),
    qty             DOUBLE NOT NULL,              -- Quantity consumed
    fk_warehouse    INT NOT NULL,                 -- Foreign key to llx_entrepot
    fk_product      INT NOT NULL,                 -- Foreign key to llx_product
    date_creation   DATETIME NOT NULL,
    tms             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fk_user_creat   INT,
    fk_user_modif   INT,
    note_public     TEXT,
    note_private    TEXT,
    status          INT DEFAULT 1,                -- 0=Draft, 1=Validated

    INDEX idx_fk_warehouse (fk_warehouse),
    INDEX idx_fk_product (fk_product),
    INDEX idx_date_creation (date_creation),

    FOREIGN KEY (fk_warehouse) REFERENCES llx_entrepot(rowid),
    FOREIGN KEY (fk_product) REFERENCES llx_product(rowid)
);
```

### Related Dolibarr Tables

**`llx_product`** - Product information
- rowid, ref, label, tosell, fk_product_type, pmp (average price)

**`llx_product_stock`** - Stock levels per warehouse
- rowid, fk_product, fk_entrepot, reel (actual stock quantity)

**`llx_stock_mouvement`** - Stock movement audit trail
- Created by verbruiken.class.php when consumption is validated
- Links consumption to inventory changes

**`llx_entrepot`** - Warehouse definitions
- rowid, ref, label

---

## Key Features

### 1. Dynamic Product Filtering

**How it works:**
1. User selects warehouse from dropdown (Select2 component)
2. JavaScript detects change event on `select[name='fk_warehouse']`
3. AJAX call to `/ajax/get_products_by_warehouse.php?warehouse_id=X`
4. Endpoint queries products with INNER JOIN to product_stock table
5. Returns JSON: `[{id, ref, label, stock}, ...]`
6. JavaScript rebuilds product dropdown with stock quantities

**Stock Display Format:**
- Stock ≥ 1000: "Product Name (1000+)"
- Stock 1-999: "Product Name (15x)"
- Stock = 0: "Product Name (0)"

**File:** `verbruiken_card.php` lines 305-470

### 2. Stock Deduction

**How it works:**
1. User submits consumption form
2. `verbruiken.class.php::create()` validates data
3. Record inserted into `llx_bpverbruik_verbruiken`
4. Status automatically set to VALIDATED (1)
5. Trigger `VERBRUIKEN_CREATE` fires
6. `MouvementStock::_create()` called with:
   - Type 1 (Manual output - allows negative stock)
   - Negative quantity (0 - qty) for stock reduction
   - Creates entry in `llx_stock_mouvement`
7. Stock level in `llx_product_stock` automatically updated

**File:** `class/verbruiken.class.php` lines 281-298

**Important:** Uses `_create()` instead of `livraison()` to bypass stock validation and allow negative stock (trusts physical count over system inventory).

### 3. Reporting System

**Features:**
- Date range filters (defaults to current month)
- Shows: Ref, Product, Qty, Warehouse, Date, Total Value
- Calculated total value: `qty * product.pmp`
- Access control via `BPVERBRUIK_REPORT_ALLOWED_USERS` config
- Export to PDF with formatted tables
- Export to Excel (CSV format)

**File:** `verbruiken_report.php`

**Access Control:**
```php
// In setup.php, configure allowed user IDs:
$conf->global->BPVERBRUIK_REPORT_ALLOWED_USERS = "1,5,12"; // Comma-separated
// If empty, only admins can access
```

---

## Technical Architecture

### CommonObject Pattern

The module uses Dolibarr's CommonObject framework:

```php
class Verbruiken extends CommonObject {
    public $fields = array(
        "qty" => array(
            "type" => "double",
            "label" => "Hoeveelheid",
            "position" => 35,
            "default" => "0",
            // ...
        ),
        // ...
    );
}
```

**Key Field Positions:**
- 25: `fk_warehouse` (Warehouse - first field)
- 30: `fk_product` (Product - second field)
- 35: `qty` (Quantity - third field)

Field rendering is automatic via `commonfields_add.tpl.php` and `commonfields_view.tpl.php`.

### AJAX Pattern

**Security:**
```php
// Define AJAX constants
define('NOTOKENRENEWAL', '1');
define('NOREQUIREMENU', '1');
define('NOREQUIREHTML', '1');
define('NOREQUIREAJAX', '1');

// Load Dolibarr
include "../../main.inc.php";

// Check permissions
if (empty($user) || empty($user->id)) {
    http_response_code(403);
    exit;
}
```

**SQL Pattern:**
```php
// Use INNER JOIN to only get products in selected warehouse
$sql = "SELECT p.rowid, p.ref, p.label, ps.reel as stock";
$sql .= " FROM ".MAIN_DB_PREFIX."product as p";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.fk_product = p.rowid";
$sql .= " WHERE ps.fk_entrepot = ".(int)$warehouse_id;
$sql .= " AND p.entity IN (".getEntity('product').")";
$sql .= " AND p.tosell = 1";           // Only sellable products
$sql .= " AND p.fk_product_type = 0";  // Only physical products (not services)
```

### JavaScript Pattern (Select2 Handling)

```javascript
$(document).ready(function() {
    // Find fields by name attribute (works with Select2)
    var warehouseField = $("select[name='fk_warehouse']");
    var productField = $("select[name='fk_product']");

    // Clear Select2 dropdown
    warehouseField.val("-1").trigger("change");

    // Detect changes
    warehouseField.change(function() {
        // AJAX call to update products
    });
});
```

---

## Important Code Patterns

### 1. Stock Movement Creation

**Pattern:** Always use `MouvementStock::_create()` for consumption

```php
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

$mouvementstock = new MouvementStock($this->db);
$result = $mouvementstock->_create(
    $user,                      // Current user
    $this->fk_product,          // Product ID
    $this->fk_warehouse,        // Warehouse ID
    (0 - $this->qty),           // Negative qty for stock reduction
    1,                          // Type 1 = Manual output (allows negative stock)
    $product->pmp,              // Average price for value tracking
    $label,                     // Movement description
    $inventorycode,             // Inventory code for grouping
    '',                         // Date (empty = now)
    '', '',                     // Eat-by, sell-by dates
    '',                         // Batch number
    false,                      // skip_batch
    0,                          // Product batch ID
    0,                          // Don't disable for subproducts
    0,                          // Don't clean empty lines
    false                       // Don't force update batch
);
```

**Why not `livraison()`?**
- `livraison()` enforces stock validation and blocks when stock is low
- `_create()` with type 1 allows negative stock
- Users register consumption based on physical count, even if system stock is incorrect

### 2. Translation Loading

```php
// In PHP files:
$langs->loadLangs(array("bpverbruik@bpverbruik", "other", "products"));
$langs->trans("Hoeveelheid");

// In JavaScript (server-side rendering):
print '<span>'.dol_escape_js($langs->trans("QuickSelect")).':</span>';
```

**Translation Files:**
- `langs/nl_NL/bpverbruik.lang` - Dutch
- `langs/en_US/bpverbruik.lang` - English

### 3. Permission Checks

```php
// Check module enabled
if (!isModEnabled("bpverbruik")) {
    accessforbidden();
}

// Check user permission
if (!$user->hasRight('bpverbruik', 'verbruiken', 'read')) {
    accessforbidden();
}

// Permission structure:
// $user->rights->bpverbruik->verbruiken->read
// $user->rights->bpverbruik->verbruiken->write
// $user->rights->bpverbruik->verbruiken->delete
```

### 4. Dolibarr Hooks

```php
$hookmanager->initHooks(array('verbruikencard', 'globalcard'));
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
```

---

## Configuration

### Module Settings (admin/setup.php)

**Report Access Control:**
```php
// Config key: BPVERBRUIK_REPORT_ALLOWED_USERS
// Value: Comma-separated user IDs (e.g., "1,5,12")
// Empty = Admin-only access
```

**To add new configuration:**
1. Edit `admin/setup.php`
2. Add form field with `dolibarr_set_const()`
3. Access via `getDolGlobalString('YOUR_CONFIG_KEY')`

### Permissions

Defined in `core/modules/modBpVerbruik.class.php`:

```php
$this->rights[$r][1] = 'Read consumption records';
$this->rights[$r][4] = 'verbruiken';
$this->rights[$r][5] = 'read';

$this->rights[$r][1] = 'Create/modify consumption records';
$this->rights[$r][4] = 'verbruiken';
$this->rights[$r][5] = 'write';

$this->rights[$r][1] = 'Delete consumption records';
$this->rights[$r][4] = 'verbruiken';
$this->rights[$r][5] = 'delete';
```

---

## Future Enhancements

### Low Stock Warnings

**Suggested Implementation:**
- Modify `ajax/get_products_by_warehouse.php` to add `low_stock` flag
- In JavaScript, add CSS class to options with stock < 5
- Style with red text or warning icon

```javascript
if (product.stock < 5 && product.stock > 0) {
    options += '<option value="' + product.id + '" class="low-stock">' + label + '</option>';
}
```

```css
.low-stock {
    color: #dc3545;
    font-weight: bold;
}
```

### Remember Last Warehouse

**Suggested Implementation:**
- Store last warehouse in `$_SESSION` or user extrafields
- On page load, check for saved value
- Pre-select warehouse (but don't trigger AJAX yet)

```php
// Save on form submit
$_SESSION['last_warehouse_'.$user->id] = $object->fk_warehouse;

// Load on page load
$default_warehouse = $_SESSION['last_warehouse_'.$user->id] ?? '';
```

### Disable Zero Stock Products

**Suggested Implementation:**
- In AJAX endpoint, add `disabled` flag to response
- In JavaScript, add `disabled` attribute to option

```javascript
if (product.stock <= 0) {
    options += '<option value="' + product.id + '" disabled>' + label + ' (Niet op voorraad)</option>';
}
```

### Keyboard Shortcuts

**Suggested Implementation:**
- Add keypress event listener for numeric keys 1-5
- Check if quantity field is focused
- Set corresponding value

```javascript
$(document).keypress(function(e) {
    if (!$('input').is(':focus')) {
        var key = String.fromCharCode(e.which);
        if (/[1-5]/.test(key)) {
            qtyField.val(key);
            qtyField.focus();
        }
    }
});
```

---

## Development Notes

### Debugging

**Enable console logging:**
- JavaScript already includes console.log statements
- Open browser DevTools (F12) → Console tab
- Look for: "BPVerbruik: Initializing product filtering"

**Check AJAX calls:**
- Network tab in DevTools
- Look for calls to `get_products_by_warehouse.php`
- Inspect JSON response

**SQL Debugging:**
```php
// In AJAX endpoint, add:
error_log("SQL: " . $sql);
error_log("Result count: " . count($products));
```

### Testing Checklist

- [ ] Warehouse dropdown shows all warehouses
- [ ] Warehouse selection is empty by default
- [ ] Product dropdown is disabled until warehouse selected
- [ ] Selecting warehouse loads products with stock quantities
- [ ] Changing warehouse clears product selection
- [ ] Products show format: "Ref - Name (15x)"
- [ ] Quantity field defaults to 0
- [ ] Plus button increments quantity
- [ ] Minus button decrements (doesn't go below 0)
- [ ] Quick buttons [1][2][3][4][5][10] set quantity
- [ ] Form submission creates record
- [ ] Stock is deducted from correct warehouse
- [ ] List view shows all consumptions
- [ ] Report page accessible to authorized users
- [ ] PDF export works
- [ ] Excel export works
- [ ] All labels in Dutch

### Common Issues

**Issue:** Products not filtering by warehouse
**Fix:** Check SQL uses INNER JOIN, not LEFT JOIN. Verify warehouse_id parameter.

**Issue:** Select2 dropdown not clearing
**Fix:** Use `.trigger("change")` after `.val()`. Check for Select2 initialization order.

**Issue:** Stock not deducting
**Fix:** Verify MouvementStock::_create() is called, not livraison(). Check user permissions.

**Issue:** AJAX 403 error
**Fix:** Check AJAX endpoint has proper NOREQUIRE* defines. Verify user session.

### Code Standards

- **Language:** Primary Dutch (UI), English (comments/code)
- **Indentation:** Tabs (Dolibarr standard)
- **Naming:** camelCase for JS, snake_case for PHP variables
- **Comments:** PHPDoc blocks for classes/methods
- **SQL:** Use `MAIN_DB_PREFIX`, cast integers with `(int)`, use bound parameters when possible

### Git Notes

Current state: Not in git repository
Recommendation: Initialize git to track changes

```bash
cd /Users/merijn/Downloads/bp-dolibarr
git init
git add custom/bpverbruik/
git commit -m "Initial commit: BP Verbruikregistratie module v1.0"
```

---

## Contact & Support

**Organization:** Wildopvang de Bonte Piet
**Module Name:** BpVerbruik
**Version:** 1.0
**Dolibarr Version:** 19.x+
**License:** GPL-3.0+

**For Development Questions:**
- Review this CLAUDE.md file
- Check `/Users/merijn/.claude/plans/shiny-moseying-swing.md` for UI/UX enhancement plan
- Inspect existing code patterns in the module
- Test changes in development environment first

---

**End of Documentation**
