<?php
/*
Plugin Name: Apriori Related Posts (Optimized)
Description: Analysiert Leser-Verhalten und zeigt verwandte Beiträge (Performance-Optimiert & Caching-Freundlich).
Version: 0.2
Author: Alexander Hacker
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Initialize Plugin Update Checker for automatic updates from GitHub repository.
 *
 * @return \YahnisElsts\PluginUpdateChecker\v5\Vcs\PluginUpdateChecker|null
 */
function ha_apriori_related_posts_get_updater() {
    static $update_checker = null;
    if ( null !== $update_checker ) {
        return $update_checker;
    }

    $puc_file = plugin_dir_path( __FILE__ ) . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists( $puc_file ) ) {
        return null;
    }

    require_once $puc_file;

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mystryx1337/ha-apriori-related-posts/',
        __FILE__,
        'ha-apriori-related-posts'
    );

    // Set default branch to main
    $update_checker->setBranch( 'main' );

    // Set GitHub Access Token (if defined/available)
    $token = defined( 'HA_GITHUB_UPDATE_TOKEN' ) ? HA_GITHUB_UPDATE_TOKEN : '';
    if ( empty( $token ) && defined( 'GITHUB_ACCESS_TOKEN' ) ) {
        $token = GITHUB_ACCESS_TOKEN;
    }
    $token = apply_filters( 'ha_github_update_token', $token, 'ha-apriori-related-posts' );

    if ( ! empty( $token ) ) {
        $update_checker->setAuthentication( $token );
    }

    return $update_checker;
}
ha_apriori_related_posts_get_updater();


// 1. Datenbanktabellen erstellen
register_activation_hook(__FILE__, 'arp_create_table');

function arp_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'arp_post_views';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        visitor_hash varchar(32) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY post_visitor (post_id, visitor_hash),
        KEY visitor_idx (visitor_hash),
        KEY date_idx (created_at)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// 2. Besucheraktionen aufzeichnen (Ohne PHP Sessions!)
add_action('template_redirect', 'arp_track_post_view');

function arp_track_post_view() {
    if (is_single()) {
        global $wpdb;
        $post_id = get_the_ID();
        
        // Verwende ein Cookie statt session_start() für bessere Performance/Caching
        $cookie_name = 'arp_visitor_id';
        if (!isset($_COOKIE[$cookie_name])) {
            $visitor_hash = md5(uniqid(rand(), true));
            // Cookie für 30 Tage setzen
            setcookie($cookie_name, $visitor_hash, time() + (86400 * 30), COOKIEPATH, COOKIE_DOMAIN);
        } else {
            $visitor_hash = preg_replace('/[^a-f0-9]/', '', $_COOKIE[$cookie_name]); // Sanitize
        }

        // DB Insert (Ignore, falls Eintrag schon existiert durch UNIQUE key)
        $table_name = $wpdb->prefix . 'arp_post_views';
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table_name (post_id, visitor_hash) VALUES (%d, %s)",
            $post_id, $visitor_hash
        ));
    }
}

// 3. Apriori-Algorithmus (Optimiert)
function arp_apriori_algorithm($min_support = 0.002, $days_history = 90) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'arp_post_views';

    // 1. Daten holen
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT visitor_hash, GROUP_CONCAT(post_id ORDER BY post_id ASC) as posts 
         FROM $table_name 
         WHERE created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
         GROUP BY visitor_hash 
         HAVING COUNT(post_id) > 1", 
        $days_history
    ));

    $pair_counts = array();
    $item_counts = array(); // Neu: Zähler für einzelne Posts
    $total_transactions = count($results);

    if ($total_transactions === 0) return array();

    // 2. Zählen (Paare UND Einzelitems)
    foreach ($results as $row) {
        $posts = explode(',', $row->posts);
        $count = count($posts);

        // Einzelne Items zählen (für die Normalisierung wichtig!)
        foreach ($posts as $p_id) {
            if (!isset($item_counts[$p_id])) $item_counts[$p_id] = 0;
            $item_counts[$p_id]++;
        }
        
        // Paare bilden
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $pair = $posts[$i] . ',' . $posts[$j];
                if (!isset($pair_counts[$pair])) {
                    $pair_counts[$pair] = 0;
                }
                $pair_counts[$pair]++;
            }
        }
    }

    // 3. Score berechnen (Jaccard) statt reiner Anzahl
    $scored_pairs = array();
    $min_count = ceil($total_transactions * $min_support);

    foreach ($pair_counts as $pair => $co_occurrence) {
        // Erstmal Mindest-Support prüfen (Performance)
        if ($co_occurrence < $min_count) continue;

        $ids = explode(',', $pair);
        $id_a = $ids[0];
        $id_b = $ids[1];

        $count_a = $item_counts[$id_a];
        $count_b = $item_counts[$id_b];

        // Jaccard-Formel: (A ∩ B) / (A ∪ B)
        // Union = Count(A) + Count(B) - Intersection(A,B)
        $union = $count_a + $count_b - $co_occurrence;
        
        if ($union > 0) {
            $score = $co_occurrence / $union;
            // Optional: Multipliziere mit Logarithmus der Koinzidenz, 
            // um extrem seltene Zufallstreffer bei sehr kleinen Datenmengen etwas abzufedern.
            // $score = $score * log($co_occurrence); 
            
            $scored_pairs[$pair] = $score;
        }
    }

    // 4. Sortieren nach Score (Relevanz), nicht mehr nach reiner Anzahl
    arsort($scored_pairs);

    // Gib die Top-Ergebnisse zurück (aber speichere auch die Rohdaten für die Anzeige, falls nötig)
    // Wir geben hier das Array zurück: Key = "ID,ID", Value = Score
    return $scored_pairs;
}

// 4. Cronjob Scheduling
register_activation_hook(__FILE__, 'arp_activate_cron');
function arp_activate_cron() {
    if (!wp_next_scheduled('arp_calculate_daily_event')) {
        wp_schedule_event(time(), 'daily', 'arp_calculate_daily_event');
    }
}

register_deactivation_hook(__FILE__, 'arp_deactivate_cron');
function arp_deactivate_cron() {
    $timestamp = wp_next_scheduled('arp_calculate_daily_event');
    if ($timestamp) wp_unschedule_event($timestamp, 'arp_calculate_daily_event');
}

// 5. Die eigentliche Berechnungs-Funktion
add_action('arp_calculate_daily_event', 'arp_run_calculation');
function arp_run_calculation() {
    global $wpdb;
    
    // a) Alte Daten bereinigen (Privacy & Performance)
    $table_name = $wpdb->prefix . 'arp_post_views';
    $wpdb->query("DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

    // b) Neu berechnen
    $frequent_itemsets = arp_apriori_algorithm(0.002, 90);
    
    // c) Speichern (False beim 3. Parameter verhindert 'autoload', gut für Performance)
    update_option('apriori_related_itemsets', $frequent_itemsets, false);
}

// Einmaliger Trigger zum Aktualisieren der Daten:
// https://intranet.hef-rof.de/wp-admin/?update_apriori=1
add_action('admin_init', 'arp_force_update_now');
function arp_force_update_now() {
    if (isset($_GET['update_apriori']) && current_user_can('manage_options')) {
        arp_run_calculation(); // Führt die neue Berechnung aus
        echo '<div class="notice notice-success"><p>Apriori-Daten wurden erfolgreich neu berechnet!</p></div>';
    }
}

// https://intranet.hef-rof.de/wp-admin/?arp_debug=1
add_action('init', 'arp_debug_check');
function arp_debug_check() {
    if (isset($_GET['arp_debug']) && current_user_can('manage_options')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'arp_post_views';

        echo '<h1>ARP Debug Diagnose</h1>';

        // 1. Gibt es überhaupt Daten?
        $total_views = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        echo "<p><strong>Gesamte Views in DB:</strong> $total_views</p>";

        // 2. Gibt es Besucher mit mehr als 1 View? (Das ist der Knackpunkt!)
        $sessions_with_multiple_views = $wpdb->get_results(
            "SELECT visitor_hash, COUNT(post_id) as view_count 
             FROM $table_name 
             GROUP BY visitor_hash 
             HAVING view_count > 1 
             LIMIT 10"
        );

        echo "<p><strong>Besucher mit >1 View:</strong> " . count($sessions_with_multiple_views) . "</p>";

        if (empty($sessions_with_multiple_views)) {
            echo "<div style='background:#fdd; padding:10px; border:1px solid red;'>
            <strong>PROBLEM GEFUNDEN:</strong> <br>
            Es gibt keine Besucher, die mehr als einen Beitrag angesehen haben. 
            Der Algorithmus braucht Paare (A und B), um Verbindungen herzustellen.
            <br><br>
            <strong>Lösung:</strong> Öffne einen inkognito Tab und klicke auf Beitrag A, dann auf Beitrag B, dann auf Beitrag C.
            </div>";
        } else {
            echo "<ul>";
            foreach($sessions_with_multiple_views as $row) {
                echo "<li>Hash: " . substr($row->visitor_hash, 0, 10) . "... hat " . $row->view_count . " Beiträge gesehen.</li>";
            }
            echo "</ul>";
        }

        // 3. Teste den Algorithmus mit Support 0 (ALLES zulassen)
        echo "<h3>Manueller Algo-Test (Min Support 0.0)</h3>";
        // Hier rufen wir die Funktion direkt auf, falls sie existiert
        if (function_exists('arp_apriori_algorithm')) {
            $test_result = arp_apriori_algorithm(0.0, 999); // 0 Support, 999 Tage
            echo "<pre>";
            print_r($test_result);
            echo "</pre>";
        }

        exit;
    }
}

// 6. Frontend Ausgabe (Hier ist dein Fix!)
add_filter('the_content', 'arp_show_related_posts', 900);

function arp_show_related_posts($content) {
    if (!is_single()) {
        return $content;
    }

    $frequent_itemsets = get_option('apriori_related_itemsets');
    if (empty($frequent_itemsets) || !is_array($frequent_itemsets)) {
        return $content;
    }

    $current_post_id = get_the_ID();
    $list_items = ''; // Buffer für die Listenelemente

    // Wir gehen durch die berechneten Paare
    // Format des Keys ist immer "kleinereID,größereID"
$limit_count = 0; // Zähler initialisieren
    $max_links = 5;   // Wie viele Links maximal?

    foreach ($frequent_itemsets as $pair_string => $score) {
        
        // 1. Limit prüfen: Wenn wir schon 5 haben, brich die Schleife ab.
        if ($limit_count >= $max_links) {
            break;
        }

        $ids = explode(',', $pair_string);
        
        // Prüfen, ob der aktuelle Post Teil dieses Paares ist
        if (in_array($current_post_id, $ids)) {
            
            // Score in Prozent umrechnen
            $match_percent = round($score * 100);

            // 2. Rausch-Filter: Alles unter 10% Relevanz ignorieren (optional)
            if ($match_percent < 10) {
                continue; 
            }

            // Finde die ID des *anderen* Posts im Paar
            $related_id = ($ids[0] == $current_post_id) ? $ids[1] : $ids[0];
            
            // Prüfen ob Post existiert und veröffentlicht ist
            if (get_post_status($related_id) === 'publish') {
                $permalink = get_permalink($related_id);
                $title = get_the_title($related_id);
                
                $list_items .= '<li>';
                $list_items .= '<a href="' . esc_url($permalink) . '">' . esc_html($title) . '</a> ';
                
                // UX: Färbe den Prozentwert leicht ein (Grau für weniger wichtig)
                $list_items .= '<span style="color:#888; font-size:0.8em;">(' . $match_percent . '% Match)</span>';
                $list_items .= '</li>';

                $limit_count++; // Zähler erhöhen, da wir einen Treffer hatten
            }
        }
    }

    // Nur ausgeben, wenn wir wirklich verwandte Beiträge gefunden haben
    if (!empty($list_items)) {
        $html = '<div class="arp-related-posts">';
        $html .= '<h3>Verwandte Beiträge</h3>';
        $html .= '<ul>' . $list_items . '</ul>';
        $html .= '</div>';
        
        return $content . $html;
    }

    return $content;
}

// Debug Helper (Nur für eingeloggte Admins sichtbar machen im Ernstfall)
add_action('wp_ajax_apriori_debug', 'arp_ajax_debug');
function arp_ajax_debug() {
    if (!current_user_can('manage_options')) wp_die();
    
    // Manuelles Triggern zum Testen
    arp_run_calculation(); 
    $res = get_option('apriori_related_itemsets');
    echo '<pre>'; print_r($res); echo '</pre>';
    wp_die();
}
?>