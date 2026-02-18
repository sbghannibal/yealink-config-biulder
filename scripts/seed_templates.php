<?php
/**
 * Seed Pre-built Yealink Config Templates
 * 
 * This script creates default configuration templates for common Yealink phone models.
 * Run after migrations: php scripts/seed_templates.php
 */

require_once __DIR__ . '/../settings/database.php';

echo PHP_EOL . "Seeding Yealink Config Templates..." . PHP_EOL;

// Templates data: [name, device_type, category, description, content]
$templates = [
    // T20/T21 Basic Templates
    [
        'T20/T21 Basic Config',
        'T21P',
        'Basic',
        'Basic configuration for T20/T21 series phones',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0
static_ip={{STATIC_IP}}
static_netmask={{STATIC_NETMASK}}
static_gateway={{STATIC_GATEWAY}}

[TIME]
ntp_server1={{NTP_SERVER}}
time_zone={{TIMEZONE}}
time_zone_name={{TIMEZONE_NAME}}
date_format=0
time_format=0

[SIP]
account.1.enable=1
account.1.label={{DEVICE_NAME}}
account.1.display_name={{DISPLAY_NAME}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}
account.1.outbound_proxy={{PABX_IP}}
account.1.outbound_port={{PABX_PORT}}

[PHONE]
handset.ringer.volume={{RING_VOLUME}}
features.pickup_code={{PICKUP_CODE}}
CFG
    ],
    
    // T40/T41/T42 Advanced Templates
    [
        'T40/T41/T42 Standard Config',
        'T42P',
        'Advanced',
        'Standard configuration for T40/T41/T42 series with BLF support',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0
static_ip={{STATIC_IP}}
static_netmask={{STATIC_NETMASK}}
static_gateway={{STATIC_GATEWAY}}
static_dns_server={{DNS_SERVER}}

[TIME]
ntp_server1={{NTP_SERVER}}
time_zone={{TIMEZONE}}
time_zone_name={{TIMEZONE_NAME}}
date_format=0
time_format=0

[SIP]
account.1.enable=1
account.1.label={{DEVICE_NAME}}
account.1.display_name={{DISPLAY_NAME}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}
account.1.outbound_proxy={{PABX_IP}}
account.1.outbound_port={{PABX_PORT}}
account.1.codec.1.enable=1
account.1.codec.1.payload_type=PCMU
account.1.codec.2.enable=1
account.1.codec.2.payload_type=PCMA
account.1.codec.3.enable=1
account.1.codec.3.payload_type=G729

[PHONE]
handset.ringer.volume={{RING_VOLUME}}
features.pickup_code={{PICKUP_CODE}}
voice_mail.number.1={{VOICEMAIL_NUMBER}}
auto_answer.enable=0
screensaver.enable=1
screensaver.timeout=60

[PROGRAMMABLE_KEYS]
programablekey.1.type=16
programablekey.1.line={{BLF_LINE_1}}
programablekey.1.value={{BLF_EXT_1}}
programablekey.1.label={{BLF_LABEL_1}}
CFG
    ],
    
    // T46/T48 Executive Templates
    [
        'T46/T48 Executive Config',
        'T48P',
        'Executive',
        'Full-featured configuration for T46/T48 executive phones',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0
static_ip={{STATIC_IP}}
static_netmask={{STATIC_NETMASK}}
static_gateway={{STATIC_GATEWAY}}
static_dns_server={{DNS_SERVER}}
vlan.internet_port_enable=1
vlan.internet_port_vid={{VLAN_VOICE_ID}}
vlan.internet_port_priority={{VLAN_VOICE_PRIORITY}}

[TIME]
ntp_server1={{NTP_SERVER}}
ntp_server2={{NTP_SERVER_2}}
time_zone={{TIMEZONE}}
time_zone_name={{TIMEZONE_NAME}}
date_format=0
time_format=0
daylight_saving_time.enable=1

[SIP]
account.1.enable=1
account.1.label={{DEVICE_NAME}}
account.1.display_name={{DISPLAY_NAME}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}
account.1.outbound_proxy={{PABX_IP}}
account.1.outbound_port={{PABX_PORT}}
account.1.transport=1
account.1.sip_trust_ctrl=0
account.1.codec.1.enable=1
account.1.codec.1.payload_type=PCMU
account.1.codec.2.enable=1
account.1.codec.2.payload_type=PCMA
account.1.codec.3.enable=1
account.1.codec.3.payload_type=G729
account.1.codec.4.enable=1
account.1.codec.4.payload_type=G722

[PHONE]
handset.ringer.volume={{RING_VOLUME}}
features.pickup_code={{PICKUP_CODE}}
voice_mail.number.1={{VOICEMAIL_NUMBER}}
auto_answer.enable=0
screensaver.enable=1
screensaver.timeout=120
backlight.active_level={{BACKLIGHT_LEVEL}}
backlight.idle_level={{BACKLIGHT_IDLE_LEVEL}}

[SECURITY]
security.user_password={{USER_PASSWORD}}
security.admin_password={{ADMIN_PASSWORD}}

[DIRECTORY]
remote_phonebook.data.1.url={{PHONEBOOK_URL}}
remote_phonebook.data.1.name={{PHONEBOOK_NAME}}
CFG
    ],
    
    // Hotel/Hospitality Template
    [
        'Hotel Guest Room Config',
        'T43P',
        'Hospitality',
        'Simplified configuration for hotel guest rooms',
        <<<'CFG'
[DEVICE_INFO]
device_name={{DEVICE_NAME}}
device_mac={{DEVICE_MAC}}

[NETWORK]
static_network_type=0

[TIME]
ntp_server1={{NTP_SERVER}}
time_zone={{TIMEZONE}}

[SIP]
account.1.enable=1
account.1.label=Room {{ROOM_NUMBER}}
account.1.display_name=Room {{ROOM_NUMBER}}
account.1.auth_name={{AUTH_NAME}}
account.1.user_name={{USER_NAME}}
account.1.password={{SIP_PASSWORD}}
account.1.sip_server_host={{PABX_IP}}
account.1.sip_server_port={{PABX_PORT}}

[PHONE]
auto_answer.enable=0
voice_mail.number.1=*97
features.forward_mode=0
features.call_waiting=0

[PROGRAMMABLE_KEYS]
# Speed dial keys for hotel services
programablekey.1.type=13
programablekey.1.line=1
programablekey.1.value={{RECEPTION_NUMBER}}
programablekey.1.label=Reception

programablekey.2.type=13
programablekey.2.line=1
programablekey.2.value={{HOUSEKEEPING_NUMBER}}
programablekey.2.label=Housekeeping

programablekey.3.type=13
programablekey.3.line=1
programablekey.3.value={{ROOM_SERVICE_NUMBER}}
programablekey.3.label=Room Service

[SECURITY]
# Lock down settings
features.user_mode_password={{USER_MODE_PASSWORD}}
features.admin_mode_password={{ADMIN_MODE_PASSWORD}}
CFG
    ],
];

try {
    $pdo->beginTransaction();
    
    // Get admin user
    $stmt = $pdo->query("SELECT id FROM admins WHERE username = 'admin' LIMIT 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $admin_id = $admin ? $admin['id'] : 1;
    
    $stmt = $pdo->prepare('
        INSERT INTO config_templates 
        (template_name, device_type_id, category, description, template_content, is_active, is_default, created_by)
        SELECT ?, dt.id, ?, ?, ?, 1, 0, ?
        FROM device_types dt
        WHERE dt.type_name = ?
        LIMIT 1
        ON DUPLICATE KEY UPDATE template_name = template_name
    ');
    
    foreach ($templates as $template) {
        list($name, $device_type, $category, $description, $content) = $template;
        
        $stmt->execute([
            $name,
            $category,
            $description,
            $content,
            $admin_id,
            $device_type
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo "✓ Created template: $name ($device_type - $category)" . PHP_EOL;
        } else {
            echo "- Template already exists: $name" . PHP_EOL;
        }
    }
    
    $pdo->commit();
    echo PHP_EOL . "Template seeding completed successfully!" . PHP_EOL;
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
?>
