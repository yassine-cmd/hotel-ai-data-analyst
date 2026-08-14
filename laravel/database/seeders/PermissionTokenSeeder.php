<?php

namespace Database\Seeders;

use App\Models\PermissionToken;
use Illuminate\Database\Seeder;

/**
 * Seeds the PMS permission-token vocabulary with the table grants discovered
 * from the Atlas Hotel Manager (see docs/admin-guide.md).
 *
 * Each token's grants are stored as {"tables": {table_name: "*"}}. A "*" value
 * means all columns; an admin can later narrow to per-column grants via the
 * admin UI. Least-privilege starting point: only the tables a token needs. The
 * admin can refine after seeding.
 */
class PermissionTokenSeeder extends Seeder
{
    private const TABLES = [
        'RESERVATION' => [
            'reservation', 'reservation_chambre', 'reservation_chambre_periode',
            'reservation_chambre_regime_periode', 'reservation_client', 'reservation_details',
            'reservation_delogement', 'planning_waitlist', 'statut_reservation', 'motif_sejour',
            'connu_via', 'origine', 'ota', 'segment', 'groupe_client', 'corporate', 'groupe_prix',
            'tarif', 'tarif_code', 'tarif_agence', 'tarif_saison', 'tarif_saison_code',
            'allotment', 'allotment_sub', 'allotment_details', 'annulation', 'raison_annulation',
            'OBSERVATIONS', 'chambre', 'client', 'agence',
        ],
        'RECEPTION' => [
            'reservation', 'reservation_chambre', 'reservation_chambre_periode',
            'client', 'client_passant', 'chambre', 'chambre_bloquee', 'raison_blocage',
            'caisse_entree', 'caisse_sortie', 'caisse', 'paiement', 'paiement_details',
            'mode_paiement', 'facture', 'facture_chambre', 'facture_charge', 'taxe', 'devise',
            'deposit', 'tdn', 'tdn_details',
        ],
        'ENTRETIEN' => [
            'chambre', 'chambre_bloquee', 'equipement_chambre', 'maintenance',
            'maintenance_employe', 'maintenance_history', 'type_maintenance', 'equipement',
            'categorie', 'produit_endommage',
        ],
        'CAISSE' => [
            'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique', 'caisse_valeur_fixee',
            'mode_paiement', 'paiement', 'paiement_details', 'deposit', 'facture', 'facture_chambre',
            'facture_charge', 'facture_groupe', 'facture_groupe_detail', 'group_facture', 'tdn', 'tdn_details',
        ],
        'CONTROLE' => [
            'trace', 'trace_regle', 'OBSERVATIONS', 'utilisateur_log',
            'facture', 'facture_chambre', 'facture_charge', 'facture_groupe', 'facture_groupe_detail',
            'tdn', 'tdn_details', 'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique',
            'caisse_valeur_fixee', 'paiement', 'paiement_details', 'mode_paiement', 'deposit',
            'produit', 'prestation', 'charge', 'charge_consommation', 'charge_composite', 'charge_pack',
            'charge_reservation', 'charge_ignore', 'charge_taxe', 'charge_spa', 'charge_event', 'charge_event_r',
            'achat', 'achat_details', 'achat_paiement', 'fournisseur', 'depense',
            'reservation', 'reservation_chambre', 'reservation_chambre_periode',
            'reservation_client', 'reservation_details', 'client', 'client_passant', 'groupe_client',
        ],
        'STATISTIQUES' => [
            'reservation', 'reservation_chambre', 'reservation_chambre_periode',
            'reservation_chambre_regime_periode', 'reservation_client', 'reservation_details',
            'reservation_delogement', 'reservation_charge', 'chambre', 'client', 'client_passant',
            'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique',
            'facture', 'facture_chambre', 'facture_charge', 'facture_groupe', 'facture_groupe_detail',
            'paiement', 'paiement_details', 'charge', 'charge_consommation', 'charge_composite',
            'charge_pack', 'charge_reservation', 'charge_spa', 'charge_event', 'charge_event_r',
            'charge_ignore', 'charge_taxe', 'produit', 'prestation', 'taxe', 'hotel',
            'trace', 'utilisateur_log', 'agence', 'allotment', 'allotment_sub', 'allotment_details',
        ],
        'ROOM_SERVICE' => [
            'charge', 'charge_reservation', 'charge_pack', 'charge_ignore', 'disabled_charges',
            'reservation_chambre', 'reservation_chambre_regime_periode',
            'produit', 'prestation', 'categorie', 'restaurant_default', 'restaurant_default_agence',
        ],
        'CHEF_RESTAURANT' => [
            'produit', 'prestation', 'restaurant_default', 'restaurant_default_agence',
            'charge', 'charge_consommation', 'charge_composite', 'charge_pack', 'charge_taxe', 'charge_ignore',
            'categorie', 'categories', 'taxe', 'reservation_chambre_periode',
        ],
        'RESTAURATION_M' => [
            'charge', 'charge_consommation', 'charge_composite', 'charge_pack',
            'charge_reservation', 'charge_ignore', 'disabled_charges',
            'produit', 'prestation', 'categorie', 'restaurant_default', 'restaurant_default_agence',
            'groupe_prix', 'tarif_agence', 'caisse', 'caisse_entree', 'caisse_sortie',
            'caisse_historique', 'caisse_valeur_fixee', 'facture_charge', 'paiement', 'paiement_details',
            'mode_paiement', 'taxe', 'devise', 'deposit', 'reservation_chambre_periode',
            'reservation_chambre_regime_periode',
        ],
        'CHEF_BAR' => [
            'produit', 'prestation', 'charge', 'charge_consommation', 'charge_composite',
            'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique', 'caisse_valeur_fixee',
            'facture_charge', 'paiement', 'paiement_details', 'mode_paiement', 'taxe',
        ],
        'RESTAURATION_I' => [
            'charge', 'charge_consommation', 'charge_composite', 'charge_pack',
            'charge_reservation', 'disabled_charges',
            'produit', 'prestation', 'restaurant_default',
            'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique', 'caisse_valeur_fixee',
            'facture_charge', 'paiement', 'paiement_details', 'mode_paiement', 'taxe', 'devise',
            'group_facture',
        ],
        'SPA' => [
            'charge_spa', 'categorie_spa', 'charge', 'charge_reservation', 'charge_consommation',
            'charge_pack', 'produit', 'prestation', 'reservation_chambre_periode',
            'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique', 'caisse_valeur_fixee',
            'facture_charge', 'paiement', 'paiement_details', 'mode_paiement', 'taxe',
        ],
        'EVENT' => [
            'charge_event', 'charge_event_r', 'charge', 'charge_reservation', 'charge_pack',
            'charge_consommation', 'produit', 'prestation', 'reservation_chambre_periode',
            'caisse', 'caisse_entree', 'caisse_sortie', 'caisse_historique', 'caisse_valeur_fixee',
            'facture_charge', 'paiement', 'paiement_details', 'mode_paiement',
        ],
    ];

    private const LABELS = [
        'RESERVATION' => 'Réservation',
        'RECEPTION' => 'Réception',
        'ENTRETIEN' => 'Gouvernante',
        'CAISSE' => 'Caisses',
        'CONTROLE' => 'Controle',
        'STATISTIQUES' => 'Statistiques',
        'ROOM_SERVICE' => 'Room Service',
        'CHEF_RESTAURANT' => 'Chef Restaurant',
        'RESTAURATION_M' => 'Restaurant',
        'CHEF_BAR' => 'Chef Bar',
        'RESTAURATION_I' => 'Lounge Bar',
        'SPA' => 'SPA',
        'EVENT' => 'Activités',
    ];

    /**
     * Tokens whose PMS role must not see chiffre d'affaires (revenue) columns.
     * Per docs/admin-guide.md the POS operational tokens are confined to
     * operational columns only; the Caisses module (CAISSE) and oversight
     * roles keep full column access.
     */
    private const RESTRICTED_TOKENS = [
        'RESTAURATION_M', 'RESTAURATION_I', 'CHEF_BAR', 'SPA', 'EVENT',
    ];

    /**
     * (table => CA money columns to withhold) for the restricted tokens above.
     */
    private const CA_EXCLUSIONS = [
        'facture_charge' => ['prix'],
        'caisse_entree'  => ['total'],
        'paiement'       => ['total'],
        'deposit'        => ['montant'],
    ];

    public function run(): void
    {
        foreach (self::TABLES as $code => $tables) {
            $tableMap = array_fill_keys(array_values(array_map('strtolower', $tables)), '*');
            if (in_array($code, self::RESTRICTED_TOKENS, true)) {
                $tableMap = $this->applyCaRestrictions($tableMap);
            }

            PermissionToken::updateOrCreate(
                ['code' => $code],
                [
                    'name' => self::LABELS[$code],
                    'grants' => ['tables' => $tableMap],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Replace "*" with an explicit column allow-list on any CA-bearing table,
     * dropping the revenue columns. If the table has no schema_metadata yet the
     * "*" is kept so access is never broken before the hotel schema is synced.
     */
    private function applyCaRestrictions(array $tableMap): array
    {
        foreach ($tableMap as $table => $spec) {
            $tbl = strtolower((string) $table);
            $excluded = self::CA_EXCLUSIONS[$tbl] ?? [];
            if (empty($excluded)) {
                continue;
            }

            $columns = \App\Models\SchemaMetadata::where('table_name', $tbl)
                ->whereNotNull('column_name')
                ->pluck('column_name')
                ->map(fn ($c) => strtolower((string) $c))
                ->all();

            if (empty($columns)) {
                continue; // no metadata yet — keep "*"
            }

            $allowed = array_values(array_diff(
                $columns,
                array_map('strtolower', $excluded)
            ));
            sort($allowed);

            $tableMap[$table] = ['columns' => $allowed];
        }

        return $tableMap;
    }
}