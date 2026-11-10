<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Assignlock\Guard;
use GlpiPlugin\Assignlock\ProfileTab;

define('PLUGIN_ASSIGNLOCK_VERSION', '1.4.1');
define('PLUGIN_ASSIGNLOCK_MIN_GLPI', '11.0.0');
define('PLUGIN_ASSIGNLOCK_MAX_GLPI', '11.1.0'); // exclusivo: aceita toda a série 11.0.x

function plugin_init_assignlock()
{
    global $PLUGIN_HOOKS;

    // Aba no cadastro de perfil (Administração > Perfis)
    Plugin::registerClass(ProfileTab::class, ['addtabon' => Profile::class]);

    // Fora de Session::getLoginUserID(): API, ações em massa e e-mail também gravam.
    // Criação: depois que o core aplicou regras e normalizou os atores.
    $PLUGIN_HOOKS[Hooks::POST_PREPAREADD]['assignlock'] = [
        Ticket::class => [Guard::class, 'onTicketAdd'],
    ];
    // Edição: antes do core transformar _actors.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['assignlock'] = [
        Ticket::class => [Guard::class, 'onTicketUpdate'],
    ];
    // Criação concluída: grava no histórico o que foi barrado enquanto o chamado ainda não tinha ID.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['assignlock'] = [
        Ticket::class => [Guard::class, 'onTicketAdded'],
    ];
    // Rede de segurança: vínculo criado direto (API Ticket_User etc.).
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['assignlock'] = [
        Ticket::class      => [Guard::class, 'onTicketPreAdd'], // foto do pedido, antes das regras
        Ticket_User::class => [Guard::class, 'onTicketUserAdd'],
    ];
}

function plugin_version_assignlock()
{
    return [
        'name'         => __('Trava de atribuição por grupo', 'assignlock'),
        'version'      => PLUGIN_ASSIGNLOCK_VERSION,
        'author'       => 'Matheus Schmidt, Pellissari',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ASSIGNLOCK_MIN_GLPI,
                'max' => PLUGIN_ASSIGNLOCK_MAX_GLPI,
            ],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_assignlock_form_url(): string
{
    global $CFG_GLPI;
    return $CFG_GLPI['root_doc'] . '/plugins/assignlock/front/profile.form.php';
}
