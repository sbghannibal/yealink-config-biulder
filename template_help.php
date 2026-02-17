<?php
/**
 * Template Variable Help - Standalone popup page
 * Shows available template variables and syntax guide
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/rbac.php';

// Ensure logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied. Please log in.');
}

$admin_id = (int) $_SESSION['admin_id'];

// Check permission
if (!has_permission($pdo, $admin_id, 'config.manage')) {
    http_response_code(403);
    exit('Access denied. Insufficient permissions.');
}

// Fetch global variables
$global_vars = [];
try {
    $stmt = $pdo->query('SELECT var_name, var_value, description FROM variables ORDER BY var_name');
    $global_vars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Failed to load global variables: ' . $e->getMessage());
}

// Device-specific variables (hardcoded, always available)
$device_vars = [
    ['DEVICE_NAME', 'Name of the device', 'Reception Phone'],
    ['DEVICE_MAC', 'MAC address (without colons)', '001565AABBCC'],
    ['DEVICE_IP', 'IP address of the device', '192.168.1.100'],
    ['DEVICE_MODEL', 'Device model/type', 'T48P'],
];

// PABX variables (always available when PABX is assigned)
$pabx_vars = [
    ['PABX_NAME', 'Name of the PABX', 'Main PABX'],
    ['PABX_IP', 'IP address of the PABX', '192.168.1.100'],
    ['PABX_PORT', 'SIP port of the PABX', '5060'],
    ['PABX_TYPE', 'Type of PABX system', 'Asterisk'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Template Variable Help - Yealink Config Builder</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 24px;
            border-bottom: 3px solid #5568d3;
        }
        
        header h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .content {
            padding: 24px;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 24px;
            overflow-x: auto;
        }
        
        .tab {
            padding: 12px 20px;
            cursor: pointer;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .tab:hover {
            color: #667eea;
            background: #f5f5f5;
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .section {
            margin-bottom: 24px;
        }
        
        .section h2 {
            font-size: 18px;
            margin-bottom: 12px;
            color: #333;
        }
        
        .section h3 {
            font-size: 16px;
            margin: 16px 0 8px 0;
            color: #555;
        }
        
        .section p {
            margin-bottom: 12px;
            line-height: 1.6;
            color: #666;
        }
        
        .variable-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            border: 1px solid #e0e0e0;
        }
        
        .variable-table th {
            background: #f5f5f5;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #333;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .variable-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        
        .variable-table tr:hover {
            background: #fafafa;
        }
        
        .variable-name {
            font-family: 'Courier New', monospace;
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            color: #d63384;
            font-weight: 600;
        }
        
        .code-block {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 16px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin-bottom: 16px;
            line-height: 1.6;
        }
        
        .code-block .comment {
            color: #6a9955;
        }
        
        .code-block .keyword {
            color: #569cd6;
        }
        
        .code-block .variable {
            color: #d63384;
            font-weight: 600;
        }
        
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        
        .info-box p {
            margin: 0;
            color: #1976d2;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px 16px;
            margin-bottom: 16px;
            border-radius: 4px;
        }
        
        .warning-box p {
            margin: 0;
            color: #856404;
        }
        
        ul {
            margin-left: 20px;
            margin-bottom: 12px;
        }
        
        ul li {
            margin-bottom: 8px;
            line-height: 1.6;
            color: #666;
        }
        
        .close-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.2s;
        }
        
        .close-btn:hover {
            background: #667eea;
            color: white;
        }
    </style>
</head>
<body>
    <button class="close-btn" onclick="window.close()">✕ Close</button>
    
    <div class="container">
        <header>
            <h1>❓ Template Variable Help</h1>
            <p>Learn how to use template variables in your Yealink configurations</p>
        </header>
        
        <div class="content">
            <div class="tabs">
                <button class="tab active" data-tab="syntax">📝 Syntax</button>
                <button class="tab" data-tab="global">🌐 Global Variables</button>
                <button class="tab" data-tab="device">📱 Device Variables</button>
                <button class="tab" data-tab="pabx">☎️ PABX Variables</button>
                <button class="tab" data-tab="examples">💡 Examples</button>
            </div>
            
            <!-- Syntax Tab -->
            <div class="tab-content active" id="syntax">
                <div class="section">
                    <h2>Template Variabele Syntax</h2>
                    
                    <div class="info-box">
                        <p><strong>Important:</strong> Template variables are automatically replaced during configuration generation.</p>
                    </div>
                    
                    <h3>Basis Syntax</h3>
                    <p>Gebruik dubbele accolades om variabelen aan te geven:</p>
                    <div class="code-block">
<span class="variable">{{VARIABLE_NAME}}</span>
                    </div>
                    
                    <h3>Regels</h3>
                    <ul>
                        <li><strong>Case-sensitive:</strong> <span class="variable-name">{{DEVICE_NAME}}</span> is anders dan <span class="variable-name">{{device_name}}</span></li>
                        <li><strong>Geen spaties:</strong> <span class="variable-name">{{DEVICE_NAME}}</span> is correct, <span class="variable-name">{{ DEVICE_NAME }}</span> niet</li>
                        <li><strong>Alleen letters, cijfers en underscores:</strong> <span class="variable-name">{{PABX_IP}}</span> ✓, <span class="variable-name">{{PABX-IP}}</span> ✗</li>
                        <li><strong>Hoofdletters aanbevolen:</strong> Voor betere leesbaarheid gebruik UPPERCASE</li>
                    </ul>
                    
                    <div class="warning-box">
                        <p><strong>Let op:</strong> Niet-bestaande variabelen blijven onveranderd in de configuratie staan ({{UNKNOWN_VAR}})</p>
                    </div>
                </div>
            </div>
            
            <!-- Global Variables Tab -->
            <div class="tab-content" id="global">
                <div class="section">
                    <h2>Globale Variabelen</h2>
                    <p>Deze variabelen zijn gedefinieerd in het systeem en beschikbaar voor alle templates.</p>
                    
                    <?php if (empty($global_vars)): ?>
                        <div class="info-box">
                            <p>Er zijn nog geen globale variabelen gedefinieerd. Voeg ze toe via Admin → Variables.</p>
                        </div>
                    <?php else: ?>
                        <table class="variable-table">
                            <thead>
                                <tr>
                                    <th>Variabele</th>
                                    <th>Huidige Waarde</th>
                                    <th>Beschrijving</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($global_vars as $var): ?>
                                    <tr>
                                        <td><span class="variable-name">{{<?php echo htmlspecialchars($var['var_name']); ?>}}</span></td>
                                        <td><?php echo htmlspecialchars($var['var_value']); ?></td>
                                        <td><?php echo htmlspecialchars($var['description'] ?: '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Device Variables Tab -->
            <div class="tab-content" id="device">
                <div class="section">
                    <h2>Device-Specifieke Variabelen</h2>
                    <p>Deze variabelen worden automatisch ingevuld op basis van het device dat de configuratie downloadt.</p>
                    
                    <table class="variable-table">
                        <thead>
                            <tr>
                                <th>Variabele</th>
                                <th>Beschrijving</th>
                                <th>Voorbeeld Waarde</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($device_vars as $var): ?>
                                <tr>
                                    <td><span class="variable-name">{{<?php echo htmlspecialchars($var[0]); ?>}}</span></td>
                                    <td><?php echo htmlspecialchars($var[1]); ?></td>
                                    <td><?php echo htmlspecialchars($var[2]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="info-box">
                        <p><strong>Automatisch:</strong> Deze variabelen worden automatisch ingevuld tijdens provisioning of download.</p>
                    </div>
                </div>
            </div>
            
            <!-- PABX Variables Tab -->
            <div class="tab-content" id="pabx">
                <div class="section">
                    <h2>PABX Variabelen</h2>
                    <p>Deze variabelen bevatten informatie over de PABX server die aan het device is toegewezen.</p>
                    
                    <table class="variable-table">
                        <thead>
                            <tr>
                                <th>Variabele</th>
                                <th>Beschrijving</th>
                                <th>Voorbeeld Waarde</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pabx_vars as $var): ?>
                                <tr>
                                    <td><span class="variable-name">{{<?php echo htmlspecialchars($var[0]); ?>}}</span></td>
                                    <td><?php echo htmlspecialchars($var[1]); ?></td>
                                    <td><?php echo htmlspecialchars($var[2]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="warning-box">
                        <p><strong>Let op:</strong> PABX variabelen zijn alleen beschikbaar als er een PABX aan het device is toegewezen.</p>
                    </div>
                </div>
            </div>
            
            <!-- Examples Tab -->
            <div class="tab-content" id="examples">
                <div class="section">
                    <h2>Praktische Voorbeelden</h2>
                    
                    <h3>Voorbeeld 1: Basis Device Info</h3>
                    <div class="code-block">
<span class="comment"># Device Information</span>
[DEVICE_INFO]
device_name=<span class="variable">{{DEVICE_NAME}}</span>
device_mac=<span class="variable">{{DEVICE_MAC}}</span>
device_ip=<span class="variable">{{DEVICE_IP}}</span>
                    </div>
                    
                    <h3>Voorbeeld 2: Netwerk Configuratie</h3>
                    <div class="code-block">
<span class="comment"># Network Settings</span>
[NETWORK]
dhcp=1
static_ip=<span class="variable">{{DEVICE_IP}}</span>
ntp_server=<span class="variable">{{NTP_SERVER}}</span>
                    </div>
                    
                    <h3>Voorbeeld 3: SIP Account Setup</h3>
                    <div class="code-block">
<span class="comment"># SIP Configuration</span>
[SIP]
proxy_ip=<span class="variable">{{PABX_IP}}</span>
proxy_port=<span class="variable">{{PABX_PORT}}</span>
registrar_ip=<span class="variable">{{PABX_IP}}</span>
registrar_port=<span class="variable">{{PABX_PORT}}</span>
                    </div>
                    
                    <h3>Voorbeeld 4: Volledige Template</h3>
                    <div class="code-block">
<span class="comment"># Yealink Phone Configuration</span>
<span class="comment"># Generated for: <span class="variable">{{DEVICE_NAME}}</span></span>

[DEVICE_INFO]
device_name=<span class="variable">{{DEVICE_NAME}}</span>
device_mac=<span class="variable">{{DEVICE_MAC}}</span>

[NETWORK]
dhcp=1
ntp_server=<span class="variable">{{NTP_SERVER}}</span>

[SIP_ACCOUNT_1]
account.1.enable=1
account.1.label=<span class="variable">{{DEVICE_NAME}}</span>
account.1.display_name=<span class="variable">{{DEVICE_NAME}}</span>
account.1.auth_name=<span class="variable">{{DEVICE_NAME}}</span>
account.1.user_name=<span class="variable">{{DEVICE_NAME}}</span>
account.1.sip_server.1.address=<span class="variable">{{PABX_IP}}</span>
account.1.sip_server.1.port=<span class="variable">{{PABX_PORT}}</span>

[PHONE_SETTINGS]
phone_setting.call_waiting=1
phone_setting.auto_answer=0
                    </div>
                    
                    <div class="info-box">
                        <p><strong>Tip:</strong> Copy these examples and adapt them for your specific use.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                // Remove active class from all tabs and contents
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Add active class to clicked tab
                tab.classList.add('active');
                
                // Show corresponding content
                const tabId = tab.getAttribute('data-tab');
                document.getElementById(tabId).classList.add('active');
            });
        });
    </script>
</body>
</html>
