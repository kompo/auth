<?php

namespace Kompo\Auth\Teams;

class TeamRoleSwitcherScopeCodec
{
    private const NODE_PREFIX = 'scope-';
    private const NODE_SEPARATOR = ':team-';

    public function scopeKey(string $roleId, int $rootTeamId): string
    {
        $json = json_encode([
            'role' => $roleId,
            'root' => $rootTeamId,
        ]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function decodeScopeKey(string $scopeKey): ?array
    {
        $base64 = strtr($scopeKey, '-_', '+/');
        $padding = strlen($base64) % 4;

        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $payload = base64_decode($base64, true);

        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);

        if (!is_array($data) || empty($data['role']) || empty($data['root'])) {
            return null;
        }

        return [
            'roleId' => (string) $data['role'],
            'rootTeamId' => (int) $data['root'],
            'scopeKey' => $scopeKey,
        ];
    }

    public function nodeId(string $scopeKey, int $teamId): string
    {
        return self::NODE_PREFIX . $scopeKey . self::NODE_SEPARATOR . $teamId;
    }

    public function parseNodeId(?string $nodeId): ?array
    {
        if (!$nodeId || !str_starts_with($nodeId, self::NODE_PREFIX)) {
            return null;
        }

        $raw = substr($nodeId, strlen(self::NODE_PREFIX));
        $parts = explode(self::NODE_SEPARATOR, $raw, 2);

        if (count($parts) !== 2 || !$parts[0] || !ctype_digit($parts[1])) {
            return null;
        }

        $scope = $this->decodeScopeKey($parts[0]);

        if (!$scope) {
            return null;
        }

        return $scope + [
            'teamId' => (int) $parts[1],
            'nodeId' => $nodeId,
        ];
    }
}
