#!version:1.0.0.1

[DEVICE_INFO]
device_mac = 80:5E:0C:F4:0B:B9

[CERTIFICATE]
static.trusted_certificates.url = https://yealink-cfg.eu/provision/staging/certificates/ca.crt
static.server_certificates.url = https://yealink-cfg.eu/provision/staging/certificates/device_805E0CF40BB9.crt
static.security.dev_cert = 1

[AUTO_PROVISION]
static.auto_provision.url = https://yealink-cfg.eu/provision/?mac=80:5E:0C:F4:0B:B9
static.auto_provision.enable = 1
feature.reboot_on_new_config = 1

[NETWORK]
static.provisioning.protocol = https
