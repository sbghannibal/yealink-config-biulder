# Yealink Staging Provisioning Setup

## Overview
This is a **2-stage provisioning process**:

### Stage 1: Staging (HTTP, No HTTPS Required Yet)
- Phone downloads `y000000000000.boot` config
- Boot config includes certificate URLs + provisioning URL
- Phone downloads CA and device certificates

### Stage 2: Full Provisioning (HTTPS with Certificates)
- Phone uses downloaded certificates for secure HTTPS connection
- Phone downloads full device configuration

---

## Phase 1: Initial Setup (Testing)

### Step 1: Get Yealink Root CA Certificate

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
2. Click "Upload Root CA Certificate"
3. Select downloaded CA certificate (.crt file)
4. Click Upload

**Verify:** You should see "✅ CA Certificate: Present"

### Step 3: Create Device Certificates

For each Yealink phone:
1. Get the phone's MAC address (Menu → Information → MAC)
2. Go to Admin → Staging Certs
3. Enter MAC address (format: 00:15:65:AA:BB:20)
4. Click "Generate Certificate"

**Note:** Currently creates placeholder. Certificate generation will be added in next phase.

### Step 4: Test Boot Configuration Download

**Method A: Browser (Simulation)**
```
http://yealink-cfg.eu/provision/staging/001565aabb20.boot
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

**Via DHCP Option 66:**
```
66 = http://yealink-cfg.eu/provision/staging/001565aabb20.boot
```

Or **Manual Configuration:**
1. Phone Web Interface → Settings → Auto Provision
2. Server URL: `http://yealink-cfg.eu/provision/staging/?mac=001565aabb20`
3. Enable Auto Provisioning
4. Reboot phone

**Expected Flow:**
1. Phone downloads boot configuration
2. Boot config includes certificate URLs
3. Phone downloads CA certificate → Device certificate
4. Phone downloads full config from `/provision/?mac=...`
5. Phone applies configuration and reboots

---

## Phase 2: Security (Later)

### Step 1: Add HTTP Basic Auth to `/provision/staging/`

**Update `.htaccess` or Apache config:**
```apache
AuthType Basic
AuthName "Provisioning"
AuthUserFile /home/admin/domains/yealink-cfg.eu/.htpasswd
Require valid-user
```

**Create password file:**
```bash
htpasswd -c /home/admin/domains/yealink-cfg.eu/.htpasswd provisioning_user
# Enter password when prompted
```

### Step 2: Update Boot Config with Credentials

Modify `provision/staging/index.php`:
```php
$boot_config = str_replace(
    'static.auto_provision.url={{SERVER_URL}}/provision/?mac={{DEVICE_MAC}}',
    'static.auto_provision.url={{SERVER_URL}}/provision/?mac={{DEVICE_MAC}}&user=provisioning_user&pass=PASSWORD',
    $boot_config
);
```

### Step 3: Update Main Provision with Auth Check

Modify `/provision/index.php` to validate credentials.

---

## Phase 3: Certificate Generation (Later)

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
