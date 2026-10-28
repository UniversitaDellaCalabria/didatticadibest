<?php

declare(strict_types=1);

namespace App\Assistenza\Didacticus;

/**
 * Tiene aggiornati i vettori di significato delle voci (dal pannello e dal cron: mai mentre una persona aspetta la risposta) e, data una domanda, dice quali voci
 * le somigliano di più. Se il servizio è spento o non risponde, ritorna null e Didacticus resta a regole.
 */
final class ServizioSignificato
{
    /** Quante voci si mandano al modello in una volta. */
    private const LOTTO = 12;

    public function __construct(private Significato $significato, private VociRepository $voci, private VettoriRepository $vettori)
    {
    }

    public function attivo(): bool
    {
        return $this->significato->attivo();
    }

    public function significato(): Significato
    {
        return $this->significato;
    }

    /** Quante voci attive ci sono e di quante il vettore è aggiornato. @return array{voci: int, pronte: int} */
    public function stato(): array
    {
        $attive = $this->voci->attive();
        $imp = $this->vettori->impronte();
        $pronte = 0;
        foreach ($attive as $v) {
            if (($imp[(int) $v['id']] ?? '') === $this->significato->impronta($v)) {
                ++$pronte;
            }
        }

        return ['voci' => count($attive), 'pronte' => $pronte];
    }

    /** Calcola i vettori delle voci nuove o cambiate. Ritorna [calcolati, voci che non si sono potute calcolare]. @return array{0: int, 1: int} */
    public function aggiorna(): array
    {
        if (!$this->attivo()) {
            return [0, 0];
        }
        $imp = $this->vettori->impronte();
        $da = [];
        foreach ($this->voci->attive() as $v) {
            if (($imp[(int) $v['id']] ?? '') !== $this->significato->impronta($v)) {
                $da[] = $v;
            }
        }
        $ok = $ko = 0;
        $quando = date('Y-m-d H:i:s');
        foreach (array_chunk($da, self::LOTTO) as $lotto) {
            $vett = $this->significato->incorpora(array_map([Significato::class, 'testoVoce'], $lotto));
            if ($vett === null) {
                $ko += count($lotto);
                continue;
            }
            foreach ($lotto as $i => $v) {
                $this->vettori->salva((int) $v['id'], $this->significato->impronta($v), $vett[$i], $quando);
                ++$ok;
            }
        }
        $this->vettori->togliInutili();

        return [$ok, $ko];
    }

    /**
     * Le voci più vicine al significato della domanda, dalla più alla meno simile; null se il servizio è spento o non risponde.
     * Contano solo le voci con il vettore aggiornato.
     *
     * @param list<array<string, mixed>> $voci le voci tra cui scegliere
     * @return list<array{voce: array<string, mixed>, somiglianza: float}>|null
     */
    public function cerca(string $domanda, array $voci, ?string $argomento = null): ?array
    {
        if (!$this->attivo() || trim($domanda) === '') {
            return null;
        }
        $vett = $this->vettori->tutti();
        if (!$vett) {
            return null;
        }
        $q = $this->significato->incorpora([$domanda]);
        if ($q === null) {
            return null;
        }
        $out = [];
        foreach ($voci as $v) {
            $e = $vett[(int) $v['id']] ?? null;
            if ($e === null || $e['impronta'] !== $this->significato->impronta($v)) {
                continue;
            }
            $s = Significato::coseno($q[0], $e['vettore']);
            if ($argomento !== null && $argomento !== '' && $argomento === ($v['argomento'] ?? '')) {
                $s += 0.03;
            }
            $out[] = ['voce' => $v, 'somiglianza' => $s];
        }
        usort($out, static fn (array $a, array $b): int => $b['somiglianza'] <=> $a['somiglianza']);

        return $out;
    }
}
