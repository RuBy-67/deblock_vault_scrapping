<?php

declare(strict_types=1);

const MONITOR_CHAIN_DECIMALS = 18;
const MONITOR_EUR_DISPLAY_DECIMALS = 2;
const MONITOR_ETH_DISPLAY_DECIMALS = 6;

/** Partie entière : groupes de 3 depuis la gauche (séparateur espace fin insécable, usage FR). */
function fr_group_int(string $intPart): string
{
    $intPart = preg_replace('/^0+/', '', $intPart) ?? '';
    if ($intPart === '') {
        $intPart = '0';
    }
    $sep = "\u{202F}";
    $result = '';
    $len = strlen($intPart);
    for ($i = 0; $i < $len; $i++) {
        if ($i > 0 && ($len - $i) % 3 === 0) {
            $result .= $sep;
        }
        $result .= $intPart[$i];
    }

    return $result;
}

/** Nombre en chaîne avec point décimal → parties avec milliers et virgule décimale. */
function fr_format_number(string $numWithDot): string
{
    $parts = explode('.', $numWithDot, 2);
    $out = fr_group_int($parts[0]);
    if (!isset($parts[1]) || $parts[1] === '') {
        return $out;
    }

    return $out . ',' . $parts[1];
}

/** Entier positif (compteurs) avec séparateurs de milliers. */
function fmt_int_fr(int $n): string
{
    return number_format($n, 0, ',', "\u{202F}");
}

/** Unités sur la chaîne → nombre avec au plus $scale décimales (chaîne). */
function raw_to_fixed(string $raw, int $chainDecimals = MONITOR_CHAIN_DECIMALS, int $scale = MONITOR_EUR_DISPLAY_DECIMALS): string
{
    if ($raw === '' || !ctype_digit((string) $raw)) {
        return $scale > 0 ? '0.' . str_repeat('0', $scale) : '0';
    }
    if (function_exists('bcdiv')) {
        return bcdiv((string) $raw, bcpow('10', (string) $chainDecimals), $scale);
    }
    $raw = (string) $raw;
    $len = strlen($raw);
    if ($len <= $chainDecimals) {
        $frac = str_pad($raw, $chainDecimals, '0', STR_PAD_LEFT);

        return '0.' . substr($frac, 0, $scale);
    }
    $i = substr($raw, 0, $len - $chainDecimals);
    $f = substr($raw, $len - $chainDecimals, $scale);

    return $i . ($scale > 0 ? '.' . str_pad($f, $scale, '0') : '');
}

/** Uint256 décimal en chaîne pour sommes ; évite les surprises PDO. */
function normalize_amount_raw(mixed $v): string
{
    $s = preg_replace('/\s+/', '', (string) $v) ?? '';
    if ($s === '' || !ctype_digit($s)) {
        return '0';
    }

    return $s;
}

/**
 * Comparaison de deux entiers décimaux positifs (chaînes). Retour &lt;0, 0, &gt;0.
 */
function monitor_cmp_digit_strings(string $a, string $b): int
{
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    $la = strlen($a);
    $lb = strlen($b);
    if ($la !== $lb) {
        return $la <=> $lb;
    }

    return strcmp($a, $b);
}

/** Somme de deux entiers positifs en notation décimale (sans float — évite 1e+25 → affichage 0). */
function monitor_digit_strings_add(string $a, string $b): string
{
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    if ($a === '0') {
        return $b;
    }
    if ($b === '0') {
        return $a;
    }
    $i = strlen($a) - 1;
    $j = strlen($b) - 1;
    $carry = 0;
    $out = '';
    while ($i >= 0 || $j >= 0 || $carry > 0) {
        $da = $i >= 0 ? (int) $a[$i] : 0;
        $db = $j >= 0 ? (int) $b[$j] : 0;
        $s = $da + $db + $carry;
        $out = (string) ($s % 10) . $out;
        $carry = intdiv($s, 10);
        $i--;
        $j--;
    }

    return $out;
}

/** Différence a − b pour a ≥ b (entiers positifs, chaînes). */
function monitor_digit_strings_sub_unsigned(string $a, string $b): string
{
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    if ($b === '0') {
        return $a;
    }
    $la = strlen($a);
    $lb = strlen($b);
    if ($la < $lb) {
        return '0';
    }
    if ($lb < $la) {
        $b = str_repeat('0', $la - $lb) . $b;
    }
    $i = $la - 1;
    $borrow = 0;
    $out = '';
    while ($i >= 0 || $borrow > 0) {
        $da = $i >= 0 ? (int) $a[$i] : 0;
        $db = $i >= 0 ? (int) $b[$i] : 0;
        $d = $da - $borrow - $db;
        if ($d < 0) {
            $d += 10;
            $borrow = 1;
        } else {
            $borrow = 0;
        }
        $out = (string) $d . $out;
        $i--;
    }
    $out = ltrim($out, '0') ?: '0';

    return $out;
}

/** a − b en entier signé (chaîne, éventuellement préfixe '-'). */
function monitor_digit_strings_sub_signed(string $a, string $b): string
{
    $cmp = monitor_cmp_digit_strings($a, $b);
    if ($cmp === 0) {
        return '0';
    }
    if ($cmp > 0) {
        return monitor_digit_strings_sub_unsigned($a, $b);
    }

    return '-' . monitor_digit_strings_sub_unsigned($b, $a);
}

/** Somme de deux montants bruts (uint256 décimal). */
function raw_add(mixed $a, mixed $b): string
{
    $x = normalize_amount_raw($a);
    $y = normalize_amount_raw($b);
    if (function_exists('bcadd')) {
        return bcadd($x, $y, 0);
    }

    return monitor_digit_strings_add($x, $y);
}

/** Différence de deux montants bruts (résultat signé si bcsub). */
function raw_sub(mixed $a, mixed $b): string
{
    $x = normalize_amount_raw($a);
    $y = normalize_amount_raw($b);
    if (function_exists('bcsub')) {
        return bcsub($x, $y, 0);
    }

    return monitor_digit_strings_sub_signed($x, $y);
}

/** Affiche un montant jeton signé (entier décimal) en ≈ €. */
function fmt_eur_signed_raw(string $signedIntStr): string
{
    $s = trim($signedIntStr);
    if ($s === '') {
        return fmt_eur('0');
    }
    $neg = str_starts_with($s, '-');
    if ($neg) {
        $s = substr($s, 1);
    }
    if (str_contains($s, '.')) {
        $s = explode('.', $s, 2)[0];
    }
    $s = ltrim($s, '0') ?: '0';
    if (!ctype_digit($s)) {
        return '—';
    }
    $core = fmt_eur($s);

    return $neg ? ('−' . $core) : $core;
}

/** Jeton indexé euro (affichage lisible, symbole €). */
function fmt_eur(string $raw): string
{
    $s = raw_to_fixed($raw, MONITOR_CHAIN_DECIMALS, MONITOR_EUR_DISPLAY_DECIMALS);

    return fr_format_number($s) . "\u{202F}€";
}

/** Coût gas depuis la base (décimal ETH), décimales limitées. */
function fmt_eth(mixed $costEth): string
{
    if ($costEth === null || $costEth === '') {
        return '—';
    }
    $s = (string) $costEth;
    if (function_exists('bcadd')) {
        $s = bcadd($s, '0', MONITOR_ETH_DISPLAY_DECIMALS);
    }
    $s = rtrim(rtrim($s, '0'), '.');
    if ($s === '') {
        $s = '0';
    }
    if (!str_contains($s, '.')) {
        return fr_group_int($s) . "\u{202F}ETH";
    }

    return fr_format_number($s) . "\u{202F}ETH";
}

/** Somme journalière (wei → unités jeton) pour graphiques ; perte minimale pour l’échelle d’un jour. */
function raw_wei_to_float_eur(string $raw): float
{
    if ($raw === '' || !ctype_digit((string) $raw)) {
        return 0.0;
    }
    if (function_exists('bcdiv')) {
        return (float) bcdiv((string) $raw, bcpow('10', (string) MONITOR_CHAIN_DECIMALS), 8);
    }

    return 0.0;
}

/** Indice lisible pour la ligne « top contreparties » (IN/OUT). */
function monitor_cp_row_hint(int $ni, int $no): string
{
    if ($ni + $no >= 50 && abs($ni - $no) <= max(5, (int) (($ni + $no) * 0.15))) {
        return 'IN≈OUT';
    }
    if ($no > $ni * 2 && $no >= 20) {
        return 'surtout OUT';
    }
    if ($ni > $no * 2 && $ni >= 20) {
        return 'surtout IN';
    }

    return '';
}
