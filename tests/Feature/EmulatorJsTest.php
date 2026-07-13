<?php

use Illuminate\Support\Facades\Process;

/**
 * ⚠️ IL JAVASCRIPT DELL'EMULATORE DEVE ALMENO COMPILARE.
 *
 * Sembra un test da poco. Non lo è: **una singola virgoletta non chiusa uccide tutto lo
 * script**, quindi il client MQTT non parte mai, e il chiosco risulta *offline*. Il sintomo
 * che si vede — "l'armadio non è raggiungibile" — punta al broker, alla rete, alle
 * credenziali: dappertutto tranne che al vero colpevole. Ci si perde un pomeriggio.
 *
 * È già successo (un `identita' creata` dentro una stringa a singoli apici).
 *
 * ⚠️ Il test **salta** se node non c'è: non vogliamo che una macchina senza node non possa
 * far girare la suite. Ma sulla macchina di chi sviluppa l'emulatore, node c'è.
 */
it('⚠️ ha un JavaScript sintatticamente valido', function () {
    $node = Process::run('node --version');

    if (! $node->successful()) {
        $this->markTestSkipped('node non è installato: niente controllo di sintassi.');
    }

    $blade = (string) file_get_contents(resource_path('views/emulator.blade.php'));

    preg_match_all('/<script>(.*?)<\/script>/s', $blade, $blocchi);

    $js = (string) end($blocchi[1]);

    expect($js)->not->toBe('');

    // Le interpolazioni Blade non sono JavaScript: si neutralizzano prima di dare in pasto il
    // file a node, altrimenti il controllo fallirebbe su `{{ $roba }}` e non ci direbbe niente.
    $js = (string) preg_replace('/@json\([^)]*\)/', '{}', $js);
    $js = (string) preg_replace('/\{\{.*?\}\}/s', '"X"', $js);
    $js = (string) preg_replace('/\{!!.*?!!\}/s', '"X"', $js);

    $file = tempnam(sys_get_temp_dir(), 'emu').'.js';
    file_put_contents($file, $js);

    $check = Process::run(['node', '--check', $file]);

    @unlink($file);

    expect($check->successful())->toBeTrue($check->errorOutput());
});
