<?php
/*
Plugin Name: Numigold Plugin
Description: Affiche un formulaire d'estimation basé sur le poids et le métal choisi, avec récupération automatique des cours via GoldAPI et mise à jour du taux de change.
Version: 1.2
Author: Jimy Marletta
*/

// === 1. CRON : Récupération quotidienne des taux via GoldAPI + Taux de change ===
add_action('metals_daily_cron', 'update_metals_prices');

function update_metals_prices() {
    $api_key = 'mykey'; // Replace with GoldAPI key
    $metals = ['XAU', 'XAG', 'XPT', 'XPD'];
    $prices = [];

    $exchange_response = wp_remote_get('https://api.exchangerate.host/latest?base=USD&symbols=EUR');
    $usd_to_eur = 0.93;
    if (!is_wp_error($exchange_response)) {
        $exchange_data = json_decode(wp_remote_retrieve_body($exchange_response), true);
        if (!empty($exchange_data['rates']['EUR'])) {
            $usd_to_eur = floatval($exchange_data['rates']['EUR']);
        }
    }

    foreach ($metals as $metal) {
        $url = "https://www.goldapi.io/api/$metal/USD";
        $response = wp_remote_get($url, [
            'headers' => [
                'x-access-token' => $api_key,
                'Content-Type' => 'application/json'
            ]
        ]);

        if (is_wp_error($response)) continue;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($data['price'])) {
            $price_per_gram_usd = $data['price'] / 31.1035;
            $price_per_gram_eur = $price_per_gram_usd * $usd_to_eur;
            $prices[$metal] = round($price_per_gram_eur, 2);
        }
    }

    if (!empty($prices)) {
        update_option('metals_rates', $prices);
        update_option('metals_last_update', current_time('mysql'));
    }
}

if (!wp_next_scheduled('metals_daily_cron')) {
    wp_schedule_event(time(), 'daily', 'metals_daily_cron');
}

// === 2. PAGE RÉGLAGES ADMIN ===
add_action('admin_menu', function() {
    add_options_page('Estimation Métaux', 'Estimation Métaux', 'manage_options', 'estimation-metaux', 'render_metals_settings_page');
});

function render_metals_settings_page() {
    if (isset($_POST['metals_margin'])) {
        update_option('metals_margin', floatval($_POST['metals_margin']));
        echo '<div class="updated"><p>Marge mise à jour.</p></div>';
    }
    $margin = get_option('metals_margin', 10);
    ?>
    <div class="wrap">
        <h1>Réglages Estimation Métaux</h1>
        <form method="post">
            <label>Marge (%) :
                <input type="number" step="0.1" name="metals_margin" value="<?php echo esc_attr($margin); ?>" />
            </label>
            <p><input type="submit" class="button-primary" value="Enregistrer" /></p>
        </form>
    </div>
    <?php
}

// === 3. SHORTCODE D'ESTIMATION ===
add_shortcode('estimate_form', 'render_estimation_form');

function render_estimation_form() {
    $rates = get_option('metals_rates', []);
    $margin = get_option('metals_margin', 10);
    ob_start();
    ?>
    <form id="metals-estimation-form">
        <label>Métal :
            <select id="metal">
                <?php foreach ($rates as $metal => $price): ?>
                    <option value="<?php echo esc_attr($metal); ?>"><?php echo esc_html($metal); ?></option>
                <?php endforeach; ?>
            </select>
        </label><br>
        <label>Poids (g) :
            <input type="number" step="0.01" id="weight" />
        </label><br>
        <p>Estimation : <strong id="estimation-result">--</strong> €</p>
    </form>

    <script type="application/json" id="metalRatesJson"><?php echo json_encode($rates); ?></script>
    <script>
        (function() {
            const rates = JSON.parse(document.getElementById('metalRatesJson').textContent);
            const margin = <?php echo floatval($margin); ?>;
            const metalSelect = document.getElementById('metal');
            const weightInput = document.getElementById('weight');
            const resultDisplay = document.getElementById('estimation-result');

            function calculate() {
                const metal = metalSelect.value;
                const weight = parseFloat(weightInput.value);
                const rate = rates[metal];
                if (!isNaN(weight) && rate) {
                    const brut = weight * rate;
                    const net = brut * (1 - (margin / 100));
                    resultDisplay.textContent = net.toFixed(2);
                } else {
                    resultDisplay.textContent = '--';
                }
            }

            metalSelect.addEventListener('change', calculate);
            weightInput.addEventListener('input', calculate);
        })();
    </script>
    <?php
    return ob_get_clean();
}

// === 4. SHORTCODE COURS DES MÉTAUX ===
add_shortcode('metals_prices', function() {
    $rates = get_option('metals_rates', []);
    $last_update = get_option('metals_last_update', '');
    if (empty($rates)) return '<p>Données non disponibles.</p>';

    $metals_names = [
        'XAU' => 'Or',
        'XAG' => 'Argent',
        'XPT' => 'Platine',
        'XPD' => 'Palladium'
    ];

    ob_start();
    ?>
    <style>
        .metals-section {
            text-align: center;
            padding: 40px 20px;
        }
        .metals-table {
            margin: 0 auto;
            width: 100%;
            max-width: 800px;
            border-collapse: collapse;
            font-family: Arial, sans-serif;
            font-size: 1.1em;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
        }
        .metals-table th, .metals-table td {
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        .metals-table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .metals-table th {
            background-color: #f1f1f1;
            text-align: center;
            font-size: 1.2em;
        }
    </style>
    <div class="metals-section">
        <h2>Prix des Métaux Précieux</h2>
        <table class="metals-table">
            <thead>
                <tr>
                    <th>Métal</th>
                    <th>Cours (€/g)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rates as $metal => $price): ?>
                <tr>
                    <td><?php echo esc_html($metals_names[$metal] ?? $metal); ?></td>
                    <td><?php echo number_format($price, 2, ',', ' '); ?> €</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size: 0.9em; color: #666; margin-top: 10px;">Dernière mise à jour : <?php echo esc_html($last_update); ?></p>
    </div>
    <?php
    return ob_get_clean();
});

// === 5. Nettoyage cron si le plugin est désactivé ===
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('metals_daily_cron');
});
