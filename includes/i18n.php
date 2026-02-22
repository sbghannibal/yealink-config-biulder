<?php
/**
 * Internationalization (i18n) helper functions
 */

/**
 * Get the current user's language preference
 */
function get_user_language(PDO $pdo, int $admin_id): string {
    // Check session first
    if (!empty($_SESSION['language'])) {
        $lang = $_SESSION['language'];
        if (in_array($lang, array_keys(get_available_languages()), true)) {
            return $lang;
        }
    }

    // Fall back to database preference
    try {
        $stmt = $pdo->prepare('SELECT language FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([$admin_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['language'])) {
            $lang = $row['language'];
            if (in_array($lang, array_keys(get_available_languages()), true)) {
                $_SESSION['language'] = $lang;
                return $lang;
            }
        }
    } catch (Exception $e) {
        error_log('i18n get_user_language error: ' . $e->getMessage());
    }

    return 'nl';
}

/**
 * Load translation strings for the given language.
 * Returns an array of key => string mappings.
 */
function load_translations(string $lang): array {
    $file = __DIR__ . '/../translations/' . $lang . '.php';
    if (file_exists($file)) {
        return include $file;
    }
    // Fallback to Dutch
    $fallback = __DIR__ . '/../translations/nl.php';
    if (file_exists($fallback)) {
        return include $fallback;
    }
    return [];
}

/**
 * Translate a key, with optional placeholder replacements.
 * Usage: __('button.save') or __('confirm.delete', ['name' => 'John'])
 */
function __(string $key, array $replacements = []): string {
    static $translations = null;
    if ($translations === null) {
        $lang = $_SESSION['language'] ?? 'nl';
        $translations = load_translations($lang);
    }

    $text = $translations[$key] ?? $key;

    foreach ($replacements as $placeholder => $value) {
        $text = str_replace(':' . $placeholder, $value, $text);
    }

    return $text;
}

/**
 * Returns available languages with their flag emoji and labels.
 */
function get_available_languages(): array {
    return [
        'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
        'fr' => ['flag' => '🇫🇷', 'label' => 'Français'],
        'en' => ['flag' => '🇺🇸', 'label' => 'English (ENG)'],
    ];
}
