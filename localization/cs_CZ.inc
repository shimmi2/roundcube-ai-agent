<?php

$labels = [];

// menu and settings
$labels['agent'] = 'AI agent';
$labels['instructions'] = 'AI instrukce pro agenty';
$labels['log'] = 'AI záznamy';
$labels['settings_ai'] = 'AI';

// API errors
$labels['apierror'] = 'Služba AI odpověděla chybou (HTTP $code).';
$labels['apidown'] = 'Služba AI je teď nedostupná, zkuste to prosím později.';

// prerequisites
$labels['notpaid'] = 'Schránka <b>$address</b> je na doméně, která nemá službu AI nad poštou.';
$labels['notpaid_more'] = 'AI nad poštou je placená služba, která tu není aktivní a je třeba ji objednat. Třídí příchozí poštu, posílá denní a týdenní přehledy a odpovídá na dotazy nad vaší poštou.';
$labels['notactive'] = 'Doména službu AI má, ale u schránky <b>$address</b> zatím aktivní není.';
$labels['notactive_more'] = 'Službu u schránky aktivuje správce z administrátorského účtu klienta — je placená, proto ji schránka nemá automaticky.';

// AI agent instructions
$labels['mailbox'] = 'Schránka';
$labels['fullname'] = 'Jméno odesílatele';
$labels['fullname_hint'] = 'Jak se zobrazí v poli Od u zpráv, které asistent pošle vaším jménem (např. denní report).';
$labels['login'] = 'Přihlašovací jméno';
$labels['password_hint'] = 'Heslo změníte v Nastavení → Heslo.';
$labels['used'] = 'Využito';
$labels['messages'] = 'Zpráv';
$labels['ai'] = 'AI nad poštou';
$labels['pipeline'] = 'AI pracuje až po průchodu filtry. Příchozí zpráva projde systémovým antispamem (ten ji jen označí), pak vašimi filtry (Nastavení → Filtry) a je doručena do složky. AI ji zpracuje zpravidla během několika vteřin po doručení; když jsou servery vytížené, podle pořadí ve frontě.';
$labels['enable'] = 'AI zapnuté pro schránku';
$labels['enable_hint'] = 'Aktivuje správce z administrátorského účtu klienta (placená služba). Bez něj neběží třídění, reporty ani chat.';
$labels['type_incoming'] = 'AI třídění příchozí pošty';
$labels['type_summary_daily'] = 'AI denní report';
$labels['type_summary_weekly'] = 'AI týdenní report';
$labels['logtype_incoming'] = 'třídění';
$labels['logtype_summary_daily'] = 'denní report';
$labels['logtype_summary_weekly'] = 'týdenní report';
$labels['logtype_live'] = 'chat';
$labels['logtype_reply'] = 'odpověď';
$labels['logtype_create'] = 'nová zpráva';
$labels['logtype_spellcheck'] = 'pravopis';
$labels['type_reply'] = 'AI odpověď';
$labels['type_reply_hint'] = 'Obecné pokyny pro odpovědi psané tlačítkem „AI odpověď“. Co odpovědět, napíšete pokaždé zvlášť.';
$labels['type_create'] = 'AI napsat';
$labels['type_create_hint'] = 'Obecné pokyny pro nové zprávy psané tlačítkem „AI napsat“. Komu a co, napíšete pokaždé zvlášť.';
$labels['type_spellcheck'] = 'AI kontrola pravopisu';
$labels['type_spellcheck_hint'] = 'Obecné pokyny ke kontrole pravopisu v editoru.';
$labels['default_task'] = 'výchozí zadání';
$labels['default_hint'] = 'Smazaný nebo nezměněný text = výchozí zadání.';
$labels['memory'] = 'Paměť AI';
$labels['memory_empty'] = 'Paměť je prázdná.';
$labels['memory_hint'] = 'Co si asistent o vás a vaší poště zapamatoval. Můžete opravit nebo smazat.';

// AI log
$labels['log_intro'] = '$n v záznamech. Výsledky jsou zkrácené; součty za měsíc zahrnují i smazané úkoly.';
$labels['log_empty'] = 'Schránka zatím nemá žádné úkoly AI.';
$labels['log_deleted'] = 'Úkoly tohoto měsíce byly smazány.';
$labels['log_sum'] = 'Celkem $n, tokeny $in / $out (vstup / výstup)';
$labels['col_when'] = 'kdy';
$labels['col_type'] = 'typ';
$labels['col_state'] = 'stav';
$labels['col_folder'] = 'složka';
$labels['col_in'] = 'tokeny vstup';
$labels['col_out'] = 'výstup';
$labels['col_result'] = 'výsledek';
$labels['state_done'] = 'hotovo';
$labels['state_error'] = 'chyba';
$labels['state_skipped'] = 'přeskočeno';
$labels['state_new'] = 'čeká';
$labels['state_running'] = 'běží';
$labels['sum_incoming'] = 'třídění';
$labels['sum_summary'] = 'reporty';
$labels['sum_live'] = 'chat';
$labels['tasks_1'] = '$n úkol';
$labels['tasks_2'] = '$n úkoly';
$labels['tasks_5'] = '$n úkolů';
$labels['newer'] = 'Novější';
$labels['older'] = 'Starší úkoly';

// chat
$labels['conversations'] = 'Konverzace';
$labels['newconv'] = 'Nová';
$labels['newconv_title'] = 'Nová konverzace';
$labels['you'] = 'Vy';
$labels['assistant'] = 'AI asistent';
$labels['send'] = 'Odeslat';
$labels['stop'] = 'Zastavit';
$labels['placeholder'] = 'Zeptejte se na svou poštu… (Enter odešle, Shift+Enter nový řádek)';
$labels['placeholder_ro'] = 'Starší konverzace jen ke čtení — začněte novou (tlačítko Nová).';
$labels['intro'] = 'Zeptejte se na cokoli ze své pošty — například „co důležitého mi přišlo tento týden?“ nebo „najdi fakturu od Alzy a shrň ji“. Asistent poštu prohledá a přečte; odpověď může trvat i několik minut.';
$labels['readonly_note'] = 'Historie téhle starší konverzace se neukládala, nedá se zobrazit ani v ní pokračovat. Začněte novou konverzaci tlačítkem Nová.';
$labels['running_note'] = 'Na tuhle konverzaci asistent právě odpovídá.';
$labels['working'] = 'Asistent pracuje…';
$labels['loading_conv'] = 'Načítám konverzaci…';
$labels['loading'] = 'Načítám…';
$labels['list_empty'] = 'Zatím žádné konverzace.';
$labels['list_error'] = 'Seznam se nepodařilo načíst.';
$labels['conv_error'] = 'Konverzaci se nepodařilo načíst.';
$labels['untitled'] = '(starší konverzace)';
$labels['tokens_title'] = 'tokeny vstup / výstup';
$labels['stopped'] = 'Zastaveno. Asistent odpověď nedokončil.';
$labels['conn_lost'] = 'Spojení s asistentem se přerušilo.';
$labels['empty_answer'] = 'Asistent vrátil prázdnou odpověď.';
$labels['no_answer'] = 'Asistent neodpověděl.';
$labels['tool_create'] = 'Návrh nové zprávy pro $to';
$labels['tool_reply'] = 'Návrh odpovědi na zprávu $msg';
$labels['opening_editor'] = 'Otevírám editor…';
$labels['tool_replace'] = 'Opravený text vložen do editoru';
$labels['revert'] = 'vrátit původní text';
$labels['reverted'] = 'Původní text je zpět v editoru.';
$labels['editor_empty'] = 'Editor je prázdný, není co kontrolovat.';
$labels['ai_spellcheck'] = 'AI pravopis';
$labels['ai_spellcheck_title'] = 'Kontrola pravopisu s AI';
$labels['intro_spellcheck'] = 'Kontroluji pravopis textu v editoru…';
$labels['ai_write'] = 'AI napsat';
$labels['ai_write_title'] = 'Napsat novou zprávu s AI';
$labels['ai_reply'] = 'AI odpověď';
$labels['ai_reply_title'] = 'Odpovědět na zprávu s AI';
$labels['intro_create'] = 'Prosím o popis, komu a co napsat. Návrh otevřu v editoru, odeslání je na vás.';
$labels['intro_reply'] = 'Prosím o popis, jak a co odpovědět. Návrh otevřu v editoru, odeslání je na vás.';
$labels['reply_to'] = 'Odpověď na: $subject';
$labels['reply_ref'] = 'Odpověď na zprávu „$subject“ (složka $folder, UID $uid).';
$labels['tool_open'] = 'Otevřena zpráva $msg';
$labels['tool_rounds'] = 'Asistent volal příliš mnoho akcí za sebou, zastaveno.';
$labels['open_in_mail'] = 'Otevřít v poště';
$labels['message'] = 'Zpráva';
$labels['retry_after'] = '(zkuste to za $s s)';
$labels['http_error'] = 'Chyba HTTP $code';
$labels['min'] = 'min';
