<?php

use GlpiPlugin\Assignlock\ProfileTab;

function plugin_assignlock_install()
{
    global $DB;

    $migration = new Migration(PLUGIN_ASSIGNLOCK_VERSION);

    // install também é o upgrade: só cria o direito nos perfis que ainda não o têm.
    $first_install = !countElementsInTable(ProfileRight::getTable(), ['name' => ProfileTab::RIGHT]);
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles']) as $profile) {
        $exists = countElementsInTable(ProfileRight::getTable(), [
            'profiles_id' => $profile['id'],
            'name'        => ProfileTab::RIGHT,
        ]);
        if (!$exists) {
            $DB->insert(ProfileRight::getTable(), [
                'profiles_id' => $profile['id'],
                'name'        => ProfileTab::RIGHT,
                'rights'      => 0,
            ]);
        }
    }

    // 1.0.x guardava as exceções numa tabela própria: migra para o direito do perfil.
    $old = 'glpi_plugin_assignlock_exemptions';
    if ($DB->tableExists($old)) {
        foreach ($DB->request(['FROM' => $old]) as $row) {
            ProfileRight::updateProfileRights((int) $row['profiles_id'], [ProfileTab::RIGHT => READ]);
        }
        $DB->dropTable($old);
    } elseif ($first_install) {
        // Padrão inicial: Super-Admin liberado.
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => ['name' => 'Super-Admin']]) as $row) {
            ProfileRight::updateProfileRights((int) $row['id'], [ProfileTab::RIGHT => READ]);
        }
    }

    $migration->executeMigration();
    return true;
}

function plugin_assignlock_uninstall()
{
    global $DB;

    ProfileRight::deleteProfileRights([ProfileTab::RIGHT]);
    if ($DB->tableExists('glpi_plugin_assignlock_exemptions')) {
        $DB->dropTable('glpi_plugin_assignlock_exemptions');
    }
    return true;
}
