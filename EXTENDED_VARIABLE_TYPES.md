# Extended Variable Types - Gebruikershandleiding

## Overzicht

Het Yealink Config Builder systeem ondersteunt nu uitgebreide variable types voor het flexibel configureren van templates. Deze handleiding beschrijft alle beschikbare types en hoe ze te gebruiken.

## Beschikbare Variable Types

### 1. Text (text)
**Gebruik:** Vrije tekst invoer voor korte waarden
- **UI Element:** Text input field
- **Validatie:** Optioneel regex pattern
- **Voorbeeld:** Device naam, extensie nummer

```json
{
  "var_name": "DEVICE_NAME",
  "var_type": "text",
  "placeholder": "Mijn Toestel",
  "is_required": true
}
```

### 2. Textarea (textarea)
**Gebruik:** Meerdere regels tekst voor grotere configuratie blokken
- **UI Element:** Textarea (4 rijen)
- **Validatie:** Optioneel regex pattern
- **Voorbeeld:** Grote configuratie blokken, notities

```json
{
  "var_name": "CUSTOM_CONFIG",
  "var_type": "textarea",
  "placeholder": "Extra configuratie regels...",
  "help_text": "Voeg hier extra configuratie regels toe"
}
```

### 3. Number (number)
**Gebruik:** Numerieke waarden met optionele min/max constraints
- **UI Element:** Number input field
- **Validatie:** Min/max waarden
- **Voorbeeld:** Poort nummer, time-out waarden

```json
{
  "var_name": "SIP_PORT",
  "var_type": "number",
  "default_value": "5060",
  "min_value": 1024,
  "max_value": 65535
}
```

### 4. Range (range)
**Gebruik:** Numerieke waarde via slider met visuele feedback
- **UI Element:** Range slider met waarde display
- **Validatie:** Min/max waarden (default 0-100)
- **Voorbeeld:** Volume, helderheid, gain

```json
{
  "var_name": "RING_VOLUME",
  "var_type": "range",
  "default_value": "5",
  "min_value": 0,
  "max_value": 10,
  "help_text": "Stel het bel volume in"
}
```

### 5. Boolean (boolean)
**Gebruik:** Ja/Nee keuze via dropdown
- **UI Element:** Select dropdown
- **Opties:** Configureerbaar via JSON (default: 0=Nee, 1=Ja)
- **Voorbeeld:** Features aan/uit zetten

```json
{
  "var_name": "ENABLE_BLF",
  "var_type": "boolean",
  "default_value": "1",
  "options": "[{\"value\":\"0\",\"label\":\"Uit\"},{\"value\":\"1\",\"label\":\"Aan\"}]"
}
```

### 6. Select (select)
**Gebruik:** Dropdown met voorgedefinieerde keuzes (enkele selectie)
- **UI Element:** Select dropdown
- **Opties:** JSON array met value/label pairs
- **Voorbeeld:** Codec selectie, taal keuze

```json
{
  "var_name": "CODEC",
  "var_type": "select",
  "options": "[{\"value\":\"g711\",\"label\":\"G.711\"},{\"value\":\"g722\",\"label\":\"G.722\"},{\"value\":\"opus\",\"label\":\"Opus\"}]",
  "is_required": true
}
```

### 7. Multiselect (multiselect)
**Gebruik:** Meerdere waarden selecteren via multi-select dropdown
- **UI Element:** Multi-select dropdown (size 5)
- **Opties:** JSON array met value/label pairs
- **Output:** Comma-separated string
- **Voorbeeld:** Meerdere codecs, extensies

```json
{
  "var_name": "ENABLED_CODECS",
  "var_type": "multiselect",
  "options": "[{\"value\":\"g711\",\"label\":\"G.711\"},{\"value\":\"g722\",\"label\":\"G.722\"},{\"value\":\"opus\",\"label\":\"Opus\"}]",
  "help_text": "Houd Ctrl/Cmd ingedrukt voor meerdere selecties"
}
```

### 8. Radio (radio)
**Gebruik:** Duidelijke keuze tussen opties via radio buttons
- **UI Element:** Radio buttons (verticaal)
- **Opties:** JSON array met value/label pairs
- **Voorbeeld:** Netwerk mode, protocol keuze

```json
{
  "var_name": "NETWORK_MODE",
  "var_type": "radio",
  "options": "[{\"value\":\"dhcp\",\"label\":\"DHCP (Automatisch)\"},{\"value\":\"static\",\"label\":\"Statisch IP\"}]",
  "default_value": "dhcp"
}
```

### 9. Checkbox Group (checkbox_group)
**Gebruik:** Meerdere features aan/uit zetten via checkboxes
- **UI Element:** Checkbox array (verticaal)
- **Opties:** JSON array met value/label pairs
- **Output:** Comma-separated string
- **Voorbeeld:** BLF toetsen, services, features

```json
{
  "var_name": "ENABLED_FEATURES",
  "var_type": "checkbox_group",
  "options": "[{\"value\":\"blf\",\"label\":\"BLF Keys\"},{\"value\":\"presence\",\"label\":\"Presence\"},{\"value\":\"voicemail\",\"label\":\"Voicemail\"}]"
}
```

### 10. Email (email)
**Gebruik:** E-mailadres met automatische validatie
- **UI Element:** Email input field
- **Validatie:** Email format (RFC 5322)
- **Voorbeeld:** Contact email, admin email

```json
{
  "var_name": "ADMIN_EMAIL",
  "var_type": "email",
  "placeholder": "admin@company.com",
  "is_required": true
}
```

### 11. URL (url)
**Gebruik:** URL met schema validatie
- **UI Element:** URL input field
- **Validatie:** Valid URL met http/https/ftp/ftps schema
- **Voorbeeld:** Provisioning URL, firmware server

```json
{
  "var_name": "PROVISIONING_URL",
  "var_type": "url",
  "placeholder": "https://pabx.example.com/provision",
  "help_text": "URL moet beginnen met http:// of https://"
}
```

### 12. Password (password)
**Gebruik:** Verborgen wachtwoord invoer
- **UI Element:** Password input field
- **Validatie:** Optioneel min_value voor minimum lengte, optioneel regex
- **Voorbeeld:** SIP wachtwoord, admin wachtwoord

```json
{
  "var_name": "SIP_PASSWORD",
  "var_type": "password",
  "min_value": 8,
  "placeholder": "Minimaal 8 tekens",
  "is_required": true
}
```

### 13. Date (date)
**Gebruik:** Datum selectie
- **UI Element:** Date picker (HTML5)
- **Validatie:** Valid date format (YYYY-MM-DD)
- **Voorbeeld:** Vervaldatum, start datum

```json
{
  "var_name": "LICENSE_EXPIRY",
  "var_type": "date",
  "help_text": "Selecteer de vervaldatum van de licentie"
}
```

### 14. IP Address (ip_address)
**Gebruik:** IP-adres validatie (IPv4 of IPv6)
- **UI Element:** Text input met IP pattern
- **Validatie:** Valid IP format
- **Voorbeeld:** PABX IP, Gateway IP

```json
{
  "var_name": "PABX_IP",
  "var_type": "ip_address",
  "placeholder": "192.168.1.100",
  "is_required": true
}
```

## Opties Format

### Simpele Array
```json
["optie1", "optie2", "optie3"]
```

### Value/Label Pairs (Aanbevolen)
```json
[
  {"value": "val1", "label": "Optie 1"},
  {"value": "val2", "label": "Optie 2"}
]
```

## Validatie

### Server-side Validatie
Alle variable types hebben ingebouwde server-side validatie:
- **Email:** RFC 5322 email format
- **URL:** Valid URL met verplicht schema
- **IP Address:** Valid IPv4 of IPv6
- **Number/Range:** Min/max constraints
- **Date:** Valid date format
- **Regex:** Custom pattern matching

### Client-side Validatie
HTML5 native validatie voor:
- Email input type
- URL input type  
- Number min/max
- Date input
- Required fields
- Pattern attribute voor custom regex

## Best Practices

1. **Gebruik duidelijke labels:** Geef een begrijpelijk label mee dat duidelijk is voor eindgebruikers
2. **Help text toevoegen:** Voeg help text toe voor complexe velden
3. **Default waarden:** Geef nuttige default waarden mee
4. **Placeholder tekst:** Gebruik placeholder voor voorbeeldwaarden
5. **Verplichte velden:** Markeer alleen echt verplichte velden als required
6. **Logische volgorde:** Gebruik display_order voor een logische flow
7. **Validatie patterns:** Gebruik regex patterns voor specifieke formaten

## Database Migratie

De database migratie `11_extended_variable_types.sql` moet worden uitgevoerd om de nieuwe types te activeren:

```sql
ALTER TABLE template_variables 
MODIFY COLUMN var_type ENUM(
    'text', 'number', 'boolean', 'select', 'multiselect',
    'textarea', 'radio', 'checkbox_group', 'email', 'url',
    'password', 'range', 'date', 'ip_address'
) DEFAULT 'text';
```

## Voorbeeld: Volledige Template Variable

```json
{
  "var_name": "SIP_USERNAME",
  "var_label": "SIP Gebruikersnaam",
  "var_type": "text",
  "default_value": "",
  "is_required": true,
  "placeholder": "1001",
  "help_text": "Voer het SIP extensie nummer in",
  "regex_pattern": "^[0-9]+$",
  "display_order": 10
}
```

## API Functies

### Rendering
```php
require_once __DIR__ . '/includes/form_helpers.php';

// Render een input veld
echo render_variable_input($variable, $current_value, [
    'show_label' => true,
    'css_class' => 'my-custom-class'
]);
```

### Validatie
```php
require_once __DIR__ . '/settings/validator.php';

// Valideer een enkele waarde
$result = validate_variable_value($value, $variable);
if (!$result['valid']) {
    echo $result['error'];
}

// Valideer meerdere variabelen
$result = validate_variables($values, $variables);
if (!$result['valid']) {
    foreach ($result['errors'] as $field => $error) {
        echo "$field: $error\n";
    }
}
```

### Display Waarde
```php
// Format een waarde voor weergave
$display = get_variable_display_value($value, $variable);
```

## Support

Voor vragen of problemen, neem contact op met de systeembeheerder of raadpleeg de template variable management interface in de admin portal: `/admin/template_variables.php`
