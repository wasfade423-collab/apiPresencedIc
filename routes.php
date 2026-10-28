<?php

declare(strict_types=1);

require_once __DIR__ . '/model/manipulation.php';

function dispatchRequest(string $method, string $path, array $body, array $headers): array
{
    $routes = [
        ['POST', '#^/auth/login$#', 'login'],
        ['POST', '#^/auth/signin$#', 'create_responsable'],
        ['PUT', '#^/auth/users/([^/]+)$#', 'update_user'],
        ['GET', '#^/membres$#', 'list_members'],
        ['POST', '#^/membres$#', 'create_member'],
        ['GET', '#^/membres/([^/]+)$#', 'get_member'],
        ['PATCH', '#^/membres/([^/]+)$#', 'update_member'],
        ['DELETE', '#^/membres/([^/]+)$#', 'delete_member'],
        ['GET', '#^/poles$#', 'list_poles'],
        ['GET', '#^/poles/([^/]+)/membres$#', 'list_pole_members'],
        ['GET', '#^/presences$#', 'list_all_presences'],
        ['POST', '#^/presences$#', 'create_presence'],
        ['GET', '#^/presences/([^/]+)$#', 'list_pole_presences'],
        ['DELETE', '#^/responsables/([^/]+)$#', 'delete_responsable'],
    ];

    $matchedRoute = null;
    $routeParams = [];
    $allowedMethods = [];
    foreach ($routes as [$routeMethod, $pattern, $action]) {
        if (preg_match($pattern, $path, $matches) === 1) {
            $allowedMethods[] = $routeMethod;
            if ($method === $routeMethod) {
                $matchedRoute = [$routeMethod, $action];
                $routeParams = array_slice($matches, 1);
                break;
            }
        }
    }

    if ($matchedRoute === null) {
        if ($allowedMethods !== []) {
            $allowedMethods = array_values(array_unique($allowedMethods));
            throw new ApiException(
                405,
                sprintf('Méthode non autorisée. Méthodes attendues : %s.', implode(', ', $allowedMethods)),
                ['Allow' => implode(', ', $allowedMethods)]
            );
        }
        throw new ApiException(404, 'Route introuvable.');
    }

    [$routeMethod, $action] = $matchedRoute;
    $model = new Model();
    if ($action === 'login') {
        return [200, $model->login($body)];
    }

    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if ($authorization === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!is_string($authorization) || preg_match('/^Bearer\s+([A-Za-z0-9._~-]+)$/i', trim($authorization), $matches) !== 1) {
        throw new ApiException(401, 'Un jeton Bearer valide est requis.');
    }

    $actor = $model->authenticate($matches[1]);
    $result = match ($action) {
        'create_responsable' => $actor['role'] === 'admin'
            ? [201, $model->createResponsible($body, $actor)]
            : throw new ApiException(403, 'La création de comptes est réservée à l’administrateur.'),
        'update_user' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->updateUser($routeParams[0], $body, $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'list_members' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->listMembers($actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'create_member' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [201, $model->createMember($body, $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'get_member' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->getMember($routeParams[0], $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'update_member' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->updateMember($routeParams[0], $body, $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'delete_member' => $actor['role'] === 'admin'
            ? [200, $model->deleteMember($routeParams[0])]
            : throw new ApiException(403, 'La suppression d’un membre est réservée à l’administrateur.'),
        'list_poles' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->listPoles($actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'list_pole_members' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->listPoleMembers($routeParams[0], $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'list_all_presences' => $actor['role'] === 'admin'
            ? [200, $model->listAllPresences($actor)]
            : throw new ApiException(403, 'La consultation globale est réservée à l’administrateur.'),
        'create_presence' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [201, $model->createPresence($body, $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'list_pole_presences' => in_array($actor['role'], ['admin', 'responsable'], true)
            ? [200, $model->listPresencesForPole($routeParams[0], $actor)]
            : throw new ApiException(403, 'Accès interdit.'),
        'delete_responsable' => $actor['role'] === 'admin'
            ? [200, $model->deleteResponsable($routeParams[0])]
            : throw new ApiException(403, 'La suppression d’un responsable est réservée à l’administrateur.'),
        default => throw new ApiException(404, 'Route introuvable.'),
    };

    return $result;
}
