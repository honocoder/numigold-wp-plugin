<?php
/*
Plugin Name: Numigold Plugin
Description: Affiche un formulaire d'estimation basé sur le poids et le métal choisi, avec récupération automatique des cours via GoldAPI et mise à jour du taux de change.
Version: 1.6
Author: Jimy Marletta
*/

// === 1. CRON : Récupération quotidienne des taux via GoldAPI + Taux de change ===
add_action('metals_daily_cron', 'update_metals_prices');

function update_metals_prices() {
    $api_key = 'goldapi-4fv4sm9ybacsp-io';
    $metals = ['XAU', 'XAG', 'XPT', 'XPD'];
    $prices = [];

    $exchange_response = wp_remote_get('https://api.frankfurter.app/latest?from=USD&to=EUR');
    $usd_to_eur = 0.93;
    if (!is_wp_error($exchange_response)) {
        $exchange_data = json_decode(wp_remote_retrieve_body($exchange_response), true);
        if (!empty($exchange_data['rates']['EUR'])) {
            $usd_to_eur = floatval($exchange_data['rates']['EUR']);
            update_option('usd_to_eur_used', $usd_to_eur);
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

        if ($metal === 'XAU' && !empty($data['price_gram_24k'])) {
            $variants = [
    'XAU_24k' => $data['price_gram_24k'],
    'XAU_22k' => $data['price_gram_22k'],
    'XAU_18k' => $data['price_gram_18k'],
    'XAU_14k' => $data['price_gram_14k'],
    'XAU_9k'  => $data['price_gram_9k']
];
            foreach ($variants as $variant => $usd_price) {
                $prices[$variant] = round($usd_price * $usd_to_eur, 2);
            }
        } elseif (!empty($data['price'])) {
            $price_per_gram_usd = $data['price'] / 31.1035;
            $prices[$metal] = round($price_per_gram_usd * $usd_to_eur, 2);
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

// TEMP : mise à jour manuelle immédiate
if (isset($_GET['force_metals_update']) && current_user_can('manage_options')) {
    update_metals_prices();
    echo "<div style='background:#dff0d8;padding:10px;border-radius:5px;'>Cours des métaux mis à jour manuellement.</div>";
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
        <p>Taux USD → EUR utilisé actuellement : <strong><?php echo number_format(get_option('usd_to_eur_used', 0.93), 4, ',', ' '); ?></strong></p>
    </div>
    <?php
}

// === 3. SHORTCODE FORMULAIRE ESTIMATION ===
add_shortcode('estimate_form', 'render_estimation_form');

function render_estimation_form() {
    $rates = get_option('metals_rates', []);
    $margin = get_option('metals_margin', 10);
    ob_start();
    ?>
    <style>
        .estimation-section {
            text-align: center;
            padding: 40px 20px;
        }
        .estimation-form {
            display: inline-block;
            text-align: left;
            max-width: 400px;
            width: 100%;
            background: #f9f9f9;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
            font-family: Arial, sans-serif;
        }
        .estimation-form label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
        }
        .estimation-form select,
        .estimation-form input {
            width: 100%;
            padding: 8px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 1em;
        }
        #estimation-result {
            font-size: 1.5em;
            color: #2a9d8f;
            display: block;
            text-align: center;
            margin-top: 20px;
        }
    </style>

    <div class="estimation-section">
        <form id="metals-estimation-form" class="estimation-form">
            <label for="metal">Métal :</label>
            <select id="metal">
                <option value="XAU">Or</option>
                <?php if (isset($rates['XAG'])): ?><option value="XAG">Argent</option><?php endif; ?>
                <?php if (isset($rates['XPT'])): ?><option value="XPT">Platine</option><?php endif; ?>
                <?php if (isset($rates['XPD'])): ?><option value="XPD">Palladium</option><?php endif; ?>
            </select>

            <div id="carat-group" style="display:none;">
                <label for="carat">Carat :</label>
                <select id="carat">
                    <?php if (isset($rates['XAU_24k'])): ?><option value="24k">24k</option><?php endif; ?>
                    <?php if (isset($rates['XAU_22k'])): ?><option value="22k">22k</option><?php endif; ?>
                    <?php if (isset($rates['XAU_18k'])): ?><option value="18k">18k</option><?php endif; ?>
                    <?php if (isset($rates['XAU_14k'])): ?><option value="14k">14k</option><?php endif; ?>
                    <?php if (isset($rates['XAU_9k'])): ?><option value="9k">9k</option><?php endif; ?>
                </select>
            </div>

            <label for="weight">Poids (g) :</label>
            <input type="number" step="0.01" id="weight" value="1" />

            <div>Estimation :</div>
            <strong id="estimation-result">-- €</strong>
        </form>
    </div>

    <script type="application/json" id="metalRatesJson"><?php echo json_encode($rates); ?></script>
    <script>
        (function() {
            const rates = JSON.parse(document.getElementById('metalRatesJson').textContent);
            const margin = <?php echo floatval($margin); ?>;
            const metalSelect = document.getElementById('metal');
            const caratGroup = document.getElementById('carat-group');
            const caratSelect = document.getElementById('carat');
            const weightInput = document.getElementById('weight');
            const resultDisplay = document.getElementById('estimation-result');

            function getSelectedRate() {
                const metal = metalSelect.value;
                if (metal === 'XAU') {
                    const carat = caratSelect.value;
                    return rates[`XAU_${carat}`] || null;
                } else {
                    return rates[metal] || null;
                }
            }

            function updateCaratVisibility() {
                caratGroup.style.display = (metalSelect.value === 'XAU') ? 'block' : 'none';
                calculate();
            }

            function calculate() {
                const weight = parseFloat(weightInput.value);
                const rate = getSelectedRate();
                if (!isNaN(weight) && rate) {
                    const brut = weight * rate;
                    const net = brut * (1 - (margin / 100));
                    resultDisplay.textContent = net.toFixed(2) + ' €';
                } else {
                    resultDisplay.textContent = '-- €';
                }
            }

            metalSelect.addEventListener('change', updateCaratVisibility);
            caratSelect.addEventListener('change', calculate);
            weightInput.addEventListener('input', calculate);
            updateCaratVisibility();
calculate();
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

    ob_start();
    ?>
    <style>
        .metals-section {
            text-align: center;
            padding: 40px 20px;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.6s ease-in-out;
        }
        .metals-section.visible {
            opacity: 1;
            transform: translateY(0);
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
    <div class="metals-section" id="metals-section">
        <h2>Prix des Métaux Précieux</h2>
        <table class="metals-table">
            <thead>
                <tr>
                    <th>Métal</th>
                    <th>Cours (€/g)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (isset($rates['XAU_24k']) || isset($rates['XAU_18k']) || isset($rates['XAU_14k'])): ?>
                    <tr><td><strong>Or 24k</strong></td><td><?php echo number_format($rates['XAU_24k'], 2, ',', ' ') ?> €</td></tr>
                    <?php if (isset($rates['XAU_18k'])): ?><tr><td>Or 18k</td><td><?php echo number_format($rates['XAU_18k'], 2, ',', ' ') ?> €</td></tr><?php endif; ?>
                    <?php if (isset($rates['XAU_14k'])): ?><tr><td>Or 14k</td><td><?php echo number_format($rates['XAU_14k'], 2, ',', ' ') ?> €</td></tr><?php endif; ?>
                <?php endif; ?>
                <?php if (isset($rates['XAG'])): ?><tr><td>Argent</td><td><?php echo number_format($rates['XAG'], 2, ',', ' ') ?> €</td></tr><?php endif; ?>
                <?php if (isset($rates['XPT'])): ?><tr><td>Platine</td><td><?php echo number_format($rates['XPT'], 2, ',', ' ') ?> €</td></tr><?php endif; ?>
                <?php if (isset($rates['XPD'])): ?><tr><td>Palladium</td><td><?php echo number_format($rates['XPD'], 2, ',', ' ') ?> €</td></tr><?php endif; ?>
            </tbody>
        </table>
        <p style="font-size: 0.9em; color: #666; margin-top: 10px;">Dernière mise à jour : <?php echo esc_html($last_update); ?></p>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('metals-section').classList.add('visible');
        });
    </script>
    <?php
    return ob_get_clean();
});

// === 5. Nettoyage cron si le plugin est désactivé ===
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('metals_daily_cron');
});