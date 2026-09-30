<?php

/**
 * Sorgular: only the default product / manufacturer brands per sector code remain (read once by the 2026_10_24
 * query pipeline migration). Filter basket terms and matching keywords live in the database.
 */
return [
    'product_brands' => [
        'dental' => ['Straumann', 'Nobel Biocare', 'Nobel', 'Osstem', 'Megagen', 'Bego', 'Hiossen', 'Neodent', 'Zimmer', 'Astra Tech',
            'Bredent', 'Dentsply', 'Implance', 'Invisalign', 'ClearCorrect', 'Clear Correct', 'Ivoclar', 'E.max'],
        'healthcare' => ['Straumann', 'Nobel Biocare', 'Osstem', 'Megagen', 'Invisalign'],
        'medical_aesthetics' => ['Juvederm', 'Restylane', 'Allergan', 'Galderma', 'Teoxane', 'Dysport'],
        'beauty' => ['Candela', 'Soprano'],
    ],
];
