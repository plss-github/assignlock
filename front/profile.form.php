<?php

use GlpiPlugin\Assignlock\ProfileTab;

Session::checkRight('profile', UPDATE);

if (isset($_POST['update'])) {
    $profile = new Profile();
    $profiles_id = (int) ($_POST['profiles_id'] ?? 0);

    // Só grava para um perfil que existe (evita linhas órfãs em glpi_profilerights).
    if ($profiles_id > 0 && $profile->getFromDB($profiles_id)) {
        ProfileRight::updateProfileRights($profiles_id, [
            ProfileTab::RIGHT => empty($_POST['bypass']) ? 0 : READ,
        ]);
        Session::addMessageAfterRedirect(htmlescape(__('Configuração salva.', 'assignlock')));
    } else {
        Session::addMessageAfterRedirect(htmlescape(__('Perfil inválido: nada foi alterado.', 'assignlock')), false, ERROR);
    }
}

Html::back();
