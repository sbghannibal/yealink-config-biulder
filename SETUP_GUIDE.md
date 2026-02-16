# Yealink Config Management System - Setup Guide

## Overzicht

Dit document beschrijft de installatie en configuratie van het Yealink Config Management System - een productie-gereed systeem voor het genereren, beheren en downloaden van Yealink telefoon configuraties.

## Vereisten

- PHP 7.4 of hoger
- MySQL 5.7 of hoger / MariaDB 10.3 or hoger
- Webserver (Apache/Nginx)
- Bestaande Yealink Config Builder installatie

## Installatie Stappen

### 1. Database Migraties Uitvoeren

Het systeem introduceert vijf nieuwe database tabellen. Voer de migraties uit in volgorde:

```bash
cd /path/to/yealink-config-builder

# Controleer eerst de migraties (dry-run)
php scripts/apply_migration_and_permissions.php --sql=migrations/03_config_versions.sql
php scripts/apply_migration_and_permissions.php --sql=migrations/05_device_config_assignments.sql
php scripts/apply_migration_and_permissions.php --sql=migrations/06_config_templates.sql
php scripts/apply_migration_and_permissions.php --sql=migrations/07_template_variables.sql

# Voer migraties uit (met --yes flag)
php scripts/apply_migration_and_permissions.php --yes --sql=migrations/03_config_versions.sql
php scripts/apply_migration_and_permissions.php --yes --sql=migrations/05_device_config_assignments.sql
php scripts/apply_migration_and_permissions.php --yes --sql=migrations/06_config_templates.sql
php scripts/apply_migration_and_permissions.php --yes --sql=migrations/07_template_variables.sql
```

**BELANGRIJK**: Maak altijd een backup van de database voordat je migraties uitvoert!

```bash
mysqldump -u [user] -p [database] > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Template Database Seeden (Optioneel)

Voeg vooraf gedefinieerde templates toe voor veelgebruikte Yealink modellen:

```bash
php scripts/seed_templates.php
```

Dit voegt templates toe voor:
- T20/T21 (Basic Config)
- T40/T41/T42 (Standard Config met BLF)
- T46/T48 (Executive Config)
- Hotel Guest Room Config

### 3. Permissies Controleren

Zorg dat de juiste RBAC permissies zijn ingesteld. De Admin rol moet de volgende permissies hebben:

- `config.manage` - Voor configuratie beheer
- `devices.manage` - Voor device beheer
- `admin.device_types.manage` - Voor device types
- `admin.variables.manage` - Voor variabelen
- `admin.tokens.manage` - Voor download tokens

### 4. Webserver Configuratie

Zorg dat de volgende bestanden toegankelijk zijn:

```
/download.php                    - Config download endpoint (publiek)
/devices/list.php               - Device lijst (ingelogd)
/devices/configure_wizard.php   - Config wizard (ingelogd)
/config/builder.php             - Config builder (ingelogd)
/config/device_mapping.php      - Device mapping UI (ingelogd)
/admin/templates.php            - Template beheer (ingelogd + permissie)
```

**Optioneel**: Maak een specifieke subdomain voor downloads:
```
https://provision.example.com/download.php?token=xxx
```

## Nieuwe Database Tabellen

### config_versions
Opslag voor configuratie versies per PABX/device type combinatie.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| pabx_id | INT | Foreign key naar pabx tabel |
| device_type_id | INT | Foreign key naar device_types |
| version_number | INT | Versie nummer |
| config_content | TEXT | Configuratie inhoud |
| changelog | VARCHAR(500) | Wijzigingslog |
| is_active | BOOLEAN | Actief status |
| created_by | INT | Admin die versie aanmaakte |
| created_at | TIMESTAMP | Aanmaak datum |

### device_config_assignments
Koppeling tussen devices en configuratie versies.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| device_id | INT | Foreign key naar devices |
| config_version_id | INT | Foreign key naar config_versions |
| assigned_by | INT | Admin die toewijzing maakte |
| assigned_at | TIMESTAMP | Toewijzing datum |
| notes | VARCHAR(500) | Notities |

### config_templates
Herbruikbare configuratie templates.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| template_name | VARCHAR(255) | Template naam |
| device_type_id | INT | Foreign key naar device_types |
| category | VARCHAR(100) | Categorie (Basic, Advanced, etc.) |
| description | TEXT | Beschrijving |
| template_content | TEXT | Template inhoud |
| is_active | BOOLEAN | Actief status |
| is_default | BOOLEAN | Standaard template voor type |
| version | VARCHAR(50) | Template versie |
| created_by | INT | Admin die template aanmaakte |

### template_variables
Variabele definities per template.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| template_id | INT | Foreign key naar config_templates |
| var_name | VARCHAR(128) | Variabele naam (b.v. STATIC_IP) |
| var_label | VARCHAR(255) | Leesbare label |
| var_type | ENUM | Type: text, number, boolean, select, ip_address |
| default_value | TEXT | Standaard waarde |
| is_required | BOOLEAN | Verplicht veld |
| validation_rule | VARCHAR(500) | Validatie regel |
| options | TEXT | JSON array voor select type |
| display_order | INT | Volgorde in UI |

### config_download_history
Audit log voor alle configuratie downloads.

| Kolom | Type | Beschrijving |
|-------|------|--------------|
| id | INT | Primary key |
| config_version_id | INT | Foreign key naar config_versions |
| device_id | INT | Foreign key naar devices (optioneel) |
| mac_address | VARCHAR(17) | MAC adres van telefoon |
| ip_address | VARCHAR(45) | IP adres van request |
| user_agent | TEXT | User agent string |
| download_time | TIMESTAMP | Download tijdstip |

## Nieuwe Functionaliteit

### 1. Config Generator (`config/generator.php`)

Core functies voor configuratie generatie:

```php
// Genereer config voor specifiek device
$result = generate_device_config($pdo, $device_id, $config_version_id);

// Genereer config vanuit template
$result = generate_config_from_template($pdo, $template_id, $variable_values);

// Formateer als Yealink .cfg bestand
$formatted = apply_yealink_formatting($content);
```

### 2. Download Endpoint (`download.php`)

Beveiligde download endpoint voor Yealink telefoons:

**URL Format:**
```
https://example.com/download.php?token=XXXX&mac=AA:BB:CC:DD:EE:FF
```

**Beveiliging:**
- Token validatie (expires_at check)
- MAC address verificatie (optioneel)
- Download history logging
- Rate limiting via token expiry

**Response:**
- Content-Type: `text/plain; charset=utf-8`
- Content-Disposition: `attachment; filename="yealink_AABBCCDDEEFF.cfg"`

### 3. Device Configuration Wizard (`devices/configure_wizard.php`)

5-stappen wizard voor device configuratie:

1. **Device Type Selectie** - Kies Yealink model
2. **Template Selectie** - Kies uit beschikbare templates
3. **Variabelen Invoeren** - Vul template-specifieke variabelen in
4. **Preview & PABX** - Bekijk config, selecteer PABX
5. **Voltooid** - Bevestiging en links

**Toegang:**
- Direct via URL: `/devices/configure_wizard.php`
- Vanuit device lijst: "⚙️ Config" button per device
- Query parameter: `?device_id=X` voor device-specifieke configuratie

### 4. Template Beheer (`admin/templates.php`)

CRUD interface voor configuratie templates:

**Functies:**
- Template aanmaken/bewerken/verwijderen
- Categorisering (Basic, Advanced, Executive, Hospitality)
- Default template per device type
- Actief/Inactief status
- Template preview

**Pre-built Templates:**
- T20/T21 Basic - Basis configuratie
- T40/T41/T42 Standard - Met BLF support
- T46/T48 Executive - Volledig uitgerust
- Hotel Guest Room - Vereenvoudigd voor hotels

### 5. Enhanced Device List (`devices/list.php`)

Uitgebreide device lijst met:

**Nieuwe Kolommen:**
- Config Versie - Badge met versie nummer
- Downloads - Aantal keer gedownload

**Nieuwe Acties:**
- ⚙️ Config button - Start wizard voor device
- Kleur-gecodeerde status (groen = config, geel = geen config)

### 6. Config Builder Extensions (`config/builder.php`)

Toegevoegde sectie: "Toewijzen aan Devices"

**Functies:**
- Config versie selectie
- Multi-select device lijst
- Bulk assignment
- Real-time device status

### 7. Device-Config Mapping (`config/device_mapping.php`)

Visuele matrix interface voor device-config relaties:

**Dashboard:**
- Totaal devices
- Toegewezen devices
- Niet toegewezen devices
- Actieve configs

**Batch Operaties:**
- Bulk assign - Wijs config toe aan meerdere devices
- Bulk unassign - Verwijder toewijzingen
- Select all checkbox
- Real-time selected count

**Device Weergave:**
- Kleur-gecodeerde randen (groen = assigned, geel = unassigned)
- Config status per device
- Individuele assign/unassign

## Yealink .cfg Format

Configuraties worden gegenereerd in het juiste Yealink .cfg formaat:

```ini
[DEVICE_INFO]
device_name=Reception Phone
device_mac=00:15:65:AA:BB:01

[NETWORK]
static_network_type=0
static_ip=192.168.1.100

[SIP]
account.1.enable=1
account.1.sip_server_host=192.168.1.200
account.1.sip_server_port=5060

[PHONE]
handset.ringer.volume=50
```

**Kenmerken:**
- Unix line endings (LF)
- Sectie headers: `[SECTION_NAME]`
- Key-value pairs: `key=value`
- Comments: `# comment` of `; comment`
- Variabele substitutie: `{{VAR_NAME}}`

## Variabelen Systeem

### Globale Variabelen

Beheer via `/admin/variables.php`:

```
{{SERVER_IP}}      → 192.168.1.100
{{SERVER_PORT}}    → 5060
{{NTP_SERVER}}     → time.nist.gov
{{TIMEZONE}}       → +1
{{TIMEZONE_NAME}}  → Europe/Amsterdam
```

### Device-Specifieke Variabelen

Automatisch toegevoegd per device:

```
{{DEVICE_NAME}}    → Device naam
{{DEVICE_MAC}}     → MAC adres
{{DEVICE_MODEL}}   → Device type
{{PABX_IP}}        → PABX IP (via config version)
{{PABX_PORT}}      → PABX poort
```

### Template Variabelen

Gedefinieerd per template in `template_variables` tabel:
- Configureerbare types (text, number, boolean, select, ip_address)
- Default values
- Validatie regels
- Required/optional status

## Workflow Voorbeelden

### Scenario 1: Nieuwe Device Configureren

1. Ga naar **Devices → List**
2. Klik op **⚙️ Config** bij gewenst device
3. Wizard start automatisch met juiste device type
4. Selecteer template (b.v. "T40/T41/T42 Standard Config")
5. Vul variabelen in (SIP credentials, BLF extensies, etc.)
6. Preview configuratie
7. Selecteer PABX en opslaan
8. Config wordt automatisch toegewezen aan device

### Scenario 2: Bulk Config Assignment

1. Ga naar **Config → Builder**
2. Maak nieuwe config versie of gebruik bestaande
3. Scroll naar "Toewijzen aan Devices"
4. Voer config versie ID in
5. Selecteer meerdere devices
6. Klik "Toewijzen"
7. Devices krijgen allemaal dezelfde config

### Scenario 3: Download Token Genereren

1. Ga naar **Config → Builder**
2. Scroll naar "Maak download token"
3. Voer config versie ID in
4. Stel geldigheid in (standaard 24 uur)
5. Klik "Genereer token"
6. Kopieer gegenereerde URL
7. Gebruik URL in Yealink provisioning (auto-provision URL)

### Scenario 4: Device Mapping Beheren

1. Ga naar **Config → Device Mapping**
2. Bekijk statistieken dashboard
3. Gebruik "Select all" voor bulk operaties
4. Filter op assigned/unassigned status
5. Batch assign of unassign configs
6. Monitor real-time assignment status

## Auto-Provisioning Setup

### Yealink Auto-Provision URL

Configureer Yealink telefoons om automatisch config te downloaden:

**Via DHCP Option 66:**
```
https://provision.example.com/download.php?token=XXXX&mac=$mac
```

**Via Admin Interface:**
```
Menu → Settings → Auto Provision
Server URL: https://provision.example.com/download.php?token=XXXX&mac=$mac
```

**$mac placeholder** wordt automatisch vervangen door telefoon's MAC adres.

### Token Beheer

**Best Practices:**
- Genereer tokens met korte geldigheid (24-48 uur) voor nieuwe deployments
- Gebruik langere geldigheid (30 dagen) voor productie omgevingen
- Monitor download history voor verdachte activiteit
- Maak nieuwe tokens aan bij security events

## Beveiliging

### CSRF Protection

Alle formulieren gebruiken CSRF tokens:
```php
$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
```

Validatie:
```php
if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    $error = 'Ongeldige aanvraag (CSRF).';
}
```

### RBAC Permissies

Vereiste permissies per sectie:

| Sectie | Permissie | Beschrijving |
|--------|-----------|--------------|
| Config Builder | `config.manage` | Config versies maken/bewerken |
| Template Beheer | `config.manage` | Templates beheren |
| Device List | `devices.manage` | Devices bekijken/beheren |
| Config Wizard | `devices.manage` | Device configs maken |
| Device Mapping | `config.manage` | Assignments beheren |

### Audit Logging

Alle belangrijke acties worden gelogd:

**Config Downloads** (`config_download_history`):
- Config versie ID
- Device ID (indien bekend)
- MAC adres
- IP adres
- User agent
- Timestamp

**Config Changes** (`audit_logs`):
- Admin ID
- Action type
- Entity type en ID
- Old/new values
- IP adres
- User agent

## Troubleshooting

### Probleem: Download geeft "Invalid token"

**Oorzaken:**
- Token verlopen
- Token niet gevonden in database
- Typfout in token

**Oplossing:**
```sql
-- Check token geldigheid
SELECT token, expires_at FROM download_tokens WHERE token = 'XXX';

-- Genereer nieuwe token via Config Builder
```

### Probleem: Config niet correct geformateerd

**Oorzaken:**
- Template bevat syntax fouten
- Variabelen niet correct gesubstitueerd

**Oplossing:**
```php
// Test formatting functie
$content = "..."; // Your content
$formatted = apply_yealink_formatting($content);
echo "<pre>" . htmlspecialchars($formatted) . "</pre>";
```

### Probleem: Device heeft geen config toegewezen

**Oorzaken:**
- Device nog niet geconfigureerd
- Assignment verwijderd

**Oplossing:**
1. Ga naar Device Mapping
2. Check of device in "Niet Toegewezen" lijst staat
3. Gebruik wizard of bulk assign om config toe te wijzen

### Probleem: Template variabelen worden niet ingevuld

**Oorzaken:**
- Variabele naam mismatch
- Globale variabelen niet ingesteld

**Oplossing:**
```sql
-- Check beschikbare variabelen
SELECT var_name, var_value FROM variables;

-- Check template variabelen
SELECT var_name, default_value FROM template_variables WHERE template_id = X;
```

## Onderhoud

### Database Optimalisatie

**Periodiek opschonen oude download history:**
```sql
-- Verwijder download logs ouder dan 90 dagen
DELETE FROM config_download_history 
WHERE download_time < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

**Index optimalisatie:**
```sql
OPTIMIZE TABLE config_download_history;
OPTIMIZE TABLE device_config_assignments;
```

### Backup Strategie

**Dagelijks:**
```bash
mysqldump -u user -p database > backup_$(date +%Y%m%d).sql
```

**Wekelijks (volledig):**
```bash
mysqldump -u user -p --all-databases > full_backup_$(date +%Y%m%d).sql
```

## API Endpoints (Optioneel)

Voor geautomatiseerde workflows kan je extra API endpoints toevoegen:

**GET /api/device_config.php?device_id=X**
- Retourneert laatst toegewezen config voor device
- Gebruikt voor externe monitoring

**POST /api/assign_config.php**
- Bulk assignment via API
- Vereist API key authenticatie

## Referenties

- [Yealink Auto-Provision Guide](http://support.yealink.com/documentFront/forwardToDocumentDetailPage?documentId=121)
- [Yealink CFG File Format](http://support.yealink.com/faq/faqInfo?id=347)
- PHP PDO Documentation: https://www.php.net/manual/en/book.pdo.php

## Support

Voor vragen of problemen:
1. Check de troubleshooting sectie
2. Bekijk audit logs voor details
3. Controleer database integriteit
4. Review PHP error logs

---

**Versie:** 1.0  
**Laatst bijgewerkt:** 2026-02-16  
**Auteur:** Yealink Config Builder Team
