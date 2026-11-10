<?php

namespace GlpiPlugin\Assignlock;

use CommonITILActor;
use Session;
use Ticket;
use Ticket_User;

/**
 * Só deixa atribuir um técnico a um chamado se esse técnico fizer parte de algum
 * grupo atribuído ao chamado. Vale para auto-atribuição e para atribuir outra pessoa.
 *
 * - Chamado sem grupo atribuído: livre.
 * - Perfil com o direito "Trava de atribuição" (aba no perfil): livre.
 * - Sem usuário logado (cron, coletor de e-mail): não se aplica.
 * - Grupos são comparados exatamente (sem herança de hierarquia).
 */
final class Guard
{
    /**
     * Técnicos pedidos pelo usuário ANTES das regras de negócio rodarem na criação.
     * Atribuições feitas por regras (configuradas por admin) não são avaliadas.
     *
     * @var \WeakMap<Ticket, list<int>>|null
     */
    private static ?\WeakMap $requested_before_rules = null;

    /**
     * Entradas de histórico de chamados em criação (ainda sem ID), gravadas em onTicketAdded().
     *
     * @var \WeakMap<Ticket, list<string>>|null
     */
    private static ?\WeakMap $pending_history = null;

    /**
     * Técnicos aprovados como "novos" na edição (tickets_id => users_id[]). Regras de negócio e
     * outros plugins ainda podem mudar os grupos DEPOIS do filtro; a conferência final acontece
     * quando o vínculo do técnico é criado (onTicketUserAdd), com os grupos finais do chamado.
     *
     * @var array<int, list<int>>
     */
    private static array $approved_new = [];

    /** @var array<int, \WeakReference<Ticket>> chamados em edição, para ler o input final */
    private static array $updating = [];

    /** Chamado criado (item_add): grava no histórico o que foi barrado durante a criação. */
    public static function onTicketAdded(Ticket $ticket): void
    {
        if (self::$pending_history === null || !self::$pending_history->offsetExists($ticket)) {
            return;
        }
        foreach (self::$pending_history[$ticket] as $text) {
            History::add((int) $ticket->getID(), $text);
        }
        unset(self::$pending_history[$ticket]);
    }

    /** Roda antes das regras (pre_item_add): registra o que o usuário pediu. */
    public static function onTicketPreAdd(Ticket $ticket): void
    {
        if (!is_array($ticket->input) || !self::mustCheck()) {
            return;
        }
        self::$requested_before_rules ??= new \WeakMap();
        self::$requested_before_rules[$ticket] = self::requestedUsers($ticket->input);
    }

    /** Roda depois das regras (post_prepareadd): vê grupos finais, inclusive os de regras. */
    public static function onTicketAdd(Ticket $ticket): void
    {
        $only = self::$requested_before_rules?->offsetExists($ticket) ? self::$requested_before_rules[$ticket] : null;
        if ($only !== null) {
            unset(self::$requested_before_rules[$ticket]);
        }
        self::filter($ticket, 0, $only);
    }

    public static function onTicketUpdate(Ticket $ticket): void
    {
        self::filter($ticket, (int) ($ticket->input['id'] ?? 0), null);
    }

    /** Rede de segurança para vínculos criados fora do fluxo do chamado. */
    public static function onTicketUserAdd(Ticket_User $link): void
    {
        $input = $link->input;
        if (!is_array($input)
            || (int) ($input['type'] ?? 0) !== CommonITILActor::ASSIGN
            || !self::mustCheck()
        ) {
            return;
        }

        $users_id   = (int) ($input['users_id'] ?? 0);
        $tickets_id = (int) ($input['tickets_id'] ?? 0);
        if ($users_id <= 0 || $tickets_id <= 0) {
            return;
        }

        if (!empty($input['_from_object'])) {
            // Vínculo criado pelo próprio chamado: o filtro já avaliou o que o usuário pediu.
            // Só reconfere o técnico aprovado, agora com os grupos FINAIS (inclui os de regras).
            $ticket = (self::$updating[$tickets_id] ?? null)?->get();
            if ($ticket === null || !in_array($users_id, self::$approved_new[$tickets_id] ?? [], true)) {
                return;
            }
            $groups = self::finalGroups($ticket, $tickets_id);
            if ($groups !== [] && !self::userInGroups($users_id, $groups)) {
                $link->input = false;
                self::notify([$users_id], $groups, $tickets_id, 'edição do chamado (grupo definido por regra)');
            }
            return;
        }

        $groups = self::assignedGroups($tickets_id);
        if ($groups !== [] && !self::userInGroups($users_id, $groups)) {
            $link->input = false;
            self::notify([$users_id], $groups, $tickets_id, 'vínculo direto (API)');
        }
    }

    private static function filter(Ticket $ticket, int $tickets_id, ?array $only): void
    {
        if (!is_array($ticket->input) || !self::mustCheck()) {
            return;
        }
        $input = $ticket->input;

        if ($tickets_id > 0 && in_array((int) ($ticket->fields['status'] ?? 0), Ticket::getClosedStatusArray(), true)) {
            return; // chamado fechado: o próprio GLPI recusa a atribuição
        }

        $requested = self::requestedUsers($input);
        if ($only !== null) {
            $requested = array_values(array_intersect($requested, $only)); // ignora o que veio de regras
        }
        if ($tickets_id > 0) {
            $requested = array_diff($requested, self::assignedUsers($tickets_id));
        }
        if ($requested === []) {
            return;
        }

        $groups = self::resultingGroups($input, $tickets_id);

        // sem grupo atribuído: qualquer técnico pode pegar
        $denied = $groups === [] ? [] : array_values(array_filter(
            $requested,
            fn(int $users_id) => !self::userInGroups($users_id, $groups)
        ));

        if ($tickets_id > 0) {
            self::$approved_new[$tickets_id] = array_values(array_diff($requested, $denied));
            self::$updating[$tickets_id]     = \WeakReference::create($ticket);
        }
        if ($denied === []) {
            return;
        }

        $ticket->input = self::stripUsers($input, $denied);
        self::notify($denied, $groups, $tickets_id, $tickets_id > 0 ? 'edição do chamado' : 'criação do chamado', $ticket);
    }

    private static function mustCheck(): bool
    {
        return Session::getLoginUserID() && !ProfileTab::currentProfileIsExempt();
    }

    /**
     * Técnicos pedidos como atribuídos, em qualquer formato de entrada:
     * `_actors` (formulário, completo), `_users_id_assign` (API/e-mail/normalizado),
     * `_itil_assign` (ações em massa).
     *
     * @return list<int>
     */
    private static function requestedUsers(array $input): array
    {
        $ids = [];

        foreach (self::actorsAssign($input) as $actor) {
            if (is_array($actor) && ($actor['itemtype'] ?? '') === 'User' && (int) ($actor['items_id'] ?? 0) > 0) {
                $ids[] = (int) $actor['items_id'];
            }
        }
        foreach ((array) ($input['_users_id_assign'] ?? []) as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }
        $itil = $input['_itil_assign'] ?? null;
        if (is_array($itil) && in_array($itil['_type'] ?? 'user', ['user'], true) && (int) ($itil['users_id'] ?? 0) > 0) {
            $ids[] = (int) $itil['users_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Grupos atribuídos como ficarão após o salvamento.
     * Com `_actors` no input, ele é o conjunto completo; sem ele, parte-se do que já está no banco.
     *
     * @return list<int>
     */
    private static function resultingGroups(array $input, int $tickets_id): array
    {
        $actors_present = self::hasActors($input);
        $groups = ($tickets_id > 0 && !$actors_present) ? self::assignedGroups($tickets_id) : [];

        foreach (self::actorsAssign($input) as $actor) {
            if (is_array($actor) && ($actor['itemtype'] ?? '') === 'Group' && (int) ($actor['items_id'] ?? 0) > 0) {
                $groups[] = (int) $actor['items_id'];
            }
        }
        foreach ((array) ($input['_groups_id_assign'] ?? []) as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $groups[] = (int) $value;
            }
        }
        $itil = $input['_itil_assign'] ?? null;
        if (is_array($itil) && ($itil['_type'] ?? '') === 'group' && (int) ($itil['groups_id'] ?? 0) > 0) {
            $groups[] = (int) $itil['groups_id'];
        }

        return array_values(array_unique($groups));
    }

    /** @return array<mixed> */
    private static function decodeActors(array $input): array
    {
        $actors = $input['_actors'] ?? null;
        if (is_string($actors)) { // GLPI pode enviar JSON
            $actors = json_decode($actors, true);
        }
        return is_array($actors) ? $actors : [];
    }

    private static function hasActors(array $input): bool
    {
        return self::decodeActors($input) !== [];
    }

    /** @return array<mixed> */
    private static function actorsAssign(array $input): array
    {
        $assign = self::decodeActors($input)['assign'] ?? [];
        return is_array($assign) ? $assign : [];
    }

    /**
     * Remove os técnicos barrados de todas as chaves de entrada, mantendo o resto do salvamento.
     *
     * @param list<int> $denied
     */
    private static function stripUsers(array $input, array $denied): array
    {
        if (array_key_exists('_actors', $input)) {
            $was_json = is_string($input['_actors']);
            $actors   = self::decodeActors($input);
            if (isset($actors['assign']) && is_array($actors['assign'])) {
                $actors['assign'] = array_values(array_filter(
                    $actors['assign'],
                    fn($a) => !(is_array($a) && ($a['itemtype'] ?? '') === 'User' && in_array((int) ($a['items_id'] ?? 0), $denied, true))
                ));
            }
            $input['_actors'] = $was_json ? json_encode($actors) : $actors;
        }

        if (array_key_exists('_users_id_assign', $input)) {
            if (is_array($input['_users_id_assign'])) {
                $input['_users_id_assign'] = array_filter(
                    $input['_users_id_assign'],
                    fn($v) => !in_array((int) $v, $denied, true)
                );
            } elseif (in_array((int) $input['_users_id_assign'], $denied, true)) {
                unset($input['_users_id_assign']);
            }
        }

        if (isset($input['_itil_assign']) && is_array($input['_itil_assign'])
            && in_array((int) ($input['_itil_assign']['users_id'] ?? 0), $denied, true)
        ) {
            unset($input['_itil_assign']);
        }

        return $input;
    }

    /**
     * Grupos atribuídos do chamado como ficarão ao fim da edição: o que está no banco, menos os
     * removidos, mais os do input já processado (regras aplicadas).
     *
     * @return list<int>
     */
    private static function finalGroups(Ticket $ticket, int $tickets_id): array
    {
        $input  = is_array($ticket->input) ? $ticket->input : [];
        $groups = self::assignedGroups($tickets_id);

        foreach ((array) ($input['_groups_id_assign_deleted'] ?? []) as $deleted) {
            $id = (int) (is_array($deleted) ? ($deleted['items_id'] ?? $deleted['groups_id'] ?? 0) : $deleted);
            $groups = array_values(array_diff($groups, [$id]));
        }
        foreach ((array) ($input['_groups_id_assign'] ?? []) as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $groups[] = (int) $value;
            }
        }
        return array_values(array_unique($groups));
    }

    /** @return list<int> */
    private static function assignedGroups(int $tickets_id): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'groups_id',
            'FROM'   => 'glpi_groups_tickets',
            'WHERE'  => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::ASSIGN],
        ]) as $row) {
            $ids[] = (int) $row['groups_id'];
        }
        return $ids;
    }

    /** @return list<int> */
    private static function assignedUsers(int $tickets_id): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $tickets_id, 'type' => CommonITILActor::ASSIGN],
        ]) as $row) {
            $ids[] = (int) $row['users_id'];
        }
        return $ids;
    }

    /** @param list<int> $groups_ids */
    private static function userInGroups(int $users_id, array $groups_ids): bool
    {
        global $DB;

        return count($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['users_id' => $users_id, 'groups_id' => $groups_ids],
            'LIMIT'  => 1,
        ])) > 0;
    }

    /**
     * @param list<int> $denied
     * @param list<int> $groups_ids
     */
    private static function notify(array $denied, array $groups_ids, int $tickets_id, string $origin, ?Ticket $new_ticket = null): void
    {
        $me     = (int) Session::getLoginUserID();
        $groups = self::groupNames($groups_ids);

        if ($denied === [$me]) {
            $msg = sprintf(
                __('Você não pode se atribuir a este chamado: ele pertence a %s. Só quem faz parte desse(s) grupo(s) pode assumi-lo.', 'assignlock'),
                $groups
            );
        } else {
            $names = array_map(fn(int $id) => getUserName($id), $denied);
            $msg = sprintf(
                __('Atribuição não realizada: %1$s não faz parte do(s) grupo(s) deste chamado (%2$s).', 'assignlock'),
                implode(', ', $names),
                $groups
            );
        }
        Session::addMessageAfterRedirect(htmlescape($msg), false, ERROR);

        // Auditoria: entrada na aba "Histórico" do chamado.
        $actor = self::clean(getUserName($me));
        foreach ($denied as $users_id) {
            $target = self::clean(getUserName($users_id));
            $text = $users_id === $me
                ? sprintf(
                    __('Auto-atribuição barrada: %1$s não faz parte dos grupos do chamado (%2$s). Origem: %3$s.', 'assignlock'),
                    $target,
                    self::clean($groups),
                    $origin
                )
                : sprintf(
                    __('Atribuição barrada: %1$s tentou atribuir %2$s, que não faz parte dos grupos do chamado (%3$s). Origem: %4$s.', 'assignlock'),
                    $actor,
                    $target,
                    self::clean($groups),
                    $origin
                );

            if ($tickets_id > 0) {
                History::add($tickets_id, $text);
            } elseif ($new_ticket !== null) {
                self::$pending_history ??= new \WeakMap();
                self::$pending_history[$new_ticket] = [...(self::$pending_history[$new_ticket] ?? []), $text];
            }
        }
    }

    /** @param list<int> $groups_ids */
    private static function groupNames(array $groups_ids): string
    {
        global $DB;

        $names = [];
        foreach ($DB->request(['SELECT' => 'name', 'FROM' => 'glpi_groups', 'WHERE' => ['id' => $groups_ids], 'ORDER' => 'name']) as $row) {
            $names[] = $row['name'];
        }
        return implode(', ', $names);
    }

    private static function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    }
}
