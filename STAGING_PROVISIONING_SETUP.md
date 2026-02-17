# Yealink Staging Provisioning Setup

## Overview
This is a **2-stage provisioning process** with enhanced security:

### Stage 1: Staging (HTTP with Authentication)
- Phone downloads `y000000000000.boot` config (with HTTP Basic Auth)
- Boot config includes certificate URLs + provisioning URL
- Phone downloads **shared** certificates (CA + Server)
- **Security:** All downloads protected with username/password

### Stage 2: Full Provisioning (HTTPS with Certificates)
- Phone uses downloaded certificates for secure HTTPS connection
- Phone downloads full device configuration
- **Security:** MAC address validated against database

---

## Security Architecture

### Multi-Layer Security

**Layer 1: HTTP Basic Authentication**
- Username/password vereist voor alle staging downloads
- Voorkomt ongeautoriseerde toegang

**Layer 2: User-Agent Verificatie** 
- Alleen Yealink apparaten mogen downloaden
- User-Agent moet "yealink" bevatten (case-insensitive)
- Voorkomt downloads via browser of curl zonder test token

**Layer 3: Gedeelde Certificaten**
- Alle devices gebruiken dezelfde certificaten
- Voorkomt MAC-spoofing aanvallen

**Layer 4: Database Validatie**
- Device moet bestaan en actief zijn in database
- MAC-adres wordt gevalideerd bij volledige provisioning

**Layer 5: IP Logging**
- Alle verzoeken worden gelogd met IP en User-Agent
- Monitoring van verdachte activiteit mogelijk

### Why Shared Certificates Instead of Per-Device?

**❌ Problem with device-specific certificates:**
- Attackers can fake MAC addresses in requests
- They could download certificates for other devices
- This gives unauthorized access to provisioning

**✅ Our secure solution:**
1. **HTTP Basic Authentication** - Only authenticated users can access staging
2. **Shared certificates** - All devices use same CA + Server certificates
3. **MAC validation in Phase 2** - Real device validation happens during full provisioning via database
4. **Result:** Faking MAC addresses doesn't help without valid credentials

---

## Phase 1: Initial Setup (Testing)

### Step 1: Configure Authentication

**IMPORTANT:** Staging provisioning is protected with HTTP Basic Authentication and User-Agent verification.

1. Go to Admin → Staging Certs
2. In the "🔐 Authentication Settings" section:
   - Set Username (default: `provisioning`)
   - Set a strong Password (min 16 characters recommended)
   - Optionally set Test Token for testing without Yealink device
   - Click "Update Authentication"

**Security Features:**
- ✅ HTTP Basic Authentication (username/password)
- ✅ User-Agent verification (only Yealink devices allowed)
- ✅ Shared certificates (prevents MAC spoofing)
- ✅ Database device validation
- ✅ IP and User-Agent logging

**Testing without Yealink device:**
- Generate test token: `openssl rand -hex 32`
- Add to .env: `STAGING_TEST_TOKEN=your_generated_token`
- Use in URL: `?allow_test=your_generated_token`

### Step 2: Get Yealink Root CA Certificate

**Option A: From Yealink Phone Directly**
1. Access phone web interface (http://phone-ip)
2. Menu → Settings → Device Information
3. Look for "Certificates" section
4. Export or note the Root CA certificate

**Option B: From Yealink Documentation**
- Contact Yealink support for Root CA certificate
- Or download from Yealink developer portal

**Option C: Extract from Phone Firmware**
```bash
# If you have phone firmware file:
unzip firmware.zip
find . -name "*.crt" -o -name "*.pem"
```

### Step 2: Upload CA Certificate

1. Go to Admin → Staging Certs
2. Scroll to "1️⃣ Upload Root CA Certificate"
3. Click "Upload Root CA Certificate"
4. Select downloaded CA certificate (.crt file)
5. Click Upload

**Verify:** You should see "✅ CA Certificate: Present"

### Step 3: Create Device Certificates

For each Yealink phone:
1. Get the phone's MAC address (Menu → Information → MAC)
2. Go to Admin → Staging Certs
3. Enter MAC address (format: 00:15:65:AA:BB:20)
4. Click "Generate Certificate"

**Note:** Currently creates placeholder. Certificate generation will be added in next phase.

### Step 4: Test Boot Configuration Download

**Method A: Browser with Authentication (Simulation)**
```
http://provisioning:your_password@yealink-cfg.eu/provision/staging/001565aabb20.boot
```

Or use curl:
```bash
curl -u provisioning:your_password http://yealink-cfg.eu/provision/staging/001565aabb20.boot
```

Should return:
```
#!version:1.0.0.1

[DEVICE_INFO]
device_mac=00:15:65:AA:BB:20

[CERTIFICATE]
static.trusted_certificates.url=http://yealink-cfg.eu/provision/staging/certificates/ca.crt
static.server_certificates.url=http://yealink-cfg.eu/provision/staging/certificates/device_001565aabb20.crt
static.security.dev_cert=1

[AUTO_PROVISION]
static.auto_provision.url=http://yealink-cfg.eu/provision/?mac=00:15:65:AA:BB:20
static.auto_provision.enable=1

feature.reboot_on_new_config=1

[NETWORK]
static.provisioning.protocol=https
```

### Step 5: Configure Phone for Auto-Provisioning

**Via DHCP Option 66 (with authentication):**
```
66 = http://provisioning:your_password@yealink-cfg.eu/provision/staging/001565aabb20.boot
```

Or **Manual Configuration:**
1. Phone Web Interface → Settings → Auto Provision
2. Server URL: `http://provisioning:your_password@yealink-cfg.eu/provision/staging/?mac=001565aabb20`
3. Or use separate fields if available:
   - Server URL: `http://yealink-cfg.eu/provision/staging/?mac=001565aabb20`
   - Username: `provisioning`
   - Password: `your_password`
4. Enable Auto Provisioning
5. Reboot phone

**Expected Flow:**
1. Phone downloads boot configuration
2. Boot config includes certificate URLs
3. Phone downloads CA certificate → Device certificate
4. Phone downloads full config from `/provision/?mac=...`
5. Phone applies configuration and reboots

---

## Phase 2: Security (Already Implemented)

### HTTP Basic Authentication

✅ **Already configured!** The staging provisioning endpoints are protected with HTTP Basic Authentication.

**Configuration:**
1. Go to Admin → Staging Certs → Authentication Settings
2. Set username and password
3. Credentials are stored in `.env` file as:
   - `STAGING_AUTH_USER=provisioning`
   - `STAGING_AUTH_PASS=your_secure_password`

**What's Protected:**
- Boot configuration endpoint (`/provision/staging/`)
- Certificate downloads (`/provision/staging/certificates/`)

**Authentication Methods for Phones:**
- Include credentials in URL: `http://user:pass@server/path`
- Use separate username/password fields in phone settings
- Configure via DHCP with embedded credentials

---

## Phase 3: Advanced Security (Optional - Later)

## Phase 3: Advanced Security (Optional - Later)

### Additional Authentication Layers

**Option A: IP Whitelist**
Add to `/provision/staging/.htaccess`:
```apache
Order Deny,Allow
Deny from all
Allow from 192.168.1.0/24
```

**Option B: Per-Device Authentication**
Generate unique credentials for each device in the database.

---

## Phase 4: Certificate Generation (Later)

Currently, device certificates are placeholders. Once ready:

1. Generate self-signed certificates for each device:
```bash
openssl req -x509 -newkey rsa:2048 \
  -keyout device_001565aabb20.key \
  -out device_001565aabb20.crt \
  -days 3650 -nodes \
  -subj "/CN=001565AABB20/O=YealinkConfig"
```

2. Implement certificate generation in admin interface
3. Store generated certificates in `/provision/staging/certificates/`

---

## Testing Checklist

- [ ] CA certificate uploaded
- [ ] Device certificate created (placeholder)
- [ ] Boot config downloads via browser
- [ ] Boot config contains correct URLs
- [ ] CA certificate is downloadable
- [ ] Phone can download boot configuration
- [ ] Phone auto-provisions after boot config
- [ ] Phone reboots after full configuration

---

## Logs & Monitoring

Check provisioning logs in Admin → Audit Logs:

```
Device: 001565aabb20
Timestamp: 2024-01-15 14:32:10
IP: 192.168.1.100
User-Agent: Yealink SIP T42P
```

---

## Troubleshooting

**Q: Phone doesn't download boot configuration**
- Check: Is MAC address format correct?
- Check: Is device active in database?
- Check: Is provisioning URL correct?

**Q: Boot config has wrong URLs**
- Check: Server URL detection (HTTP vs HTTPS)
- Check: MAC formatting in URLs

**Q: Phone doesn't download certificates**
- Check: Are certificate files present?
- Check: Are file permissions correct (644)?

---

## Next Steps

1. Test basic provisioning (current)
2. Add HTTP Basic Auth (Phase 2)
3. Implement certificate generation (Phase 3)
4. Add device certificate verification (Phase 4)
