 <div class="mb-5">
            <label for="version" class="form-label">Versione Minecraft</label>
            <select name="version" id="version" class="form-select" required>
                <?php
                $versions = [
                    "1.21.8", "1.21.7", "1.21.6", "1.21.5", "1.21.4", "1.21.3", "1.21.2", "1.21.1", "1.21",
                    "1.20.6", "1.20.5", "1.20.4", "1.20.3", "1.20.2", "1.20.1", "1.20",
                    "1.19.4", "1.19.3", "1.19.2", "1.19.1", "1.19",
                    "1.18.2", "1.18.1", "1.18",
                    "1.17.1", "1.17",
                    "1.16.5", "1.16.4", "1.16.3", "1.16.2", "1.16.1", "1.16",
                    "1.15.2", "1.15.1", "1.15",
                    "1.14.4", "1.14.3", "1.14.2", "1.14.1", "1.14",
                    "1.13.2", "1.13.1", "1.13",
                    "1.12.2", "1.12.1", "1.12",
                    "1.11.2", "1.11.1", "1.11",
                    "1.10.2", "1.10.1", "1.10",
                    "1.9.4", "1.9.3", "1.9.2", "1.9.1", "1.9",
                    "1.8.9", "1.8.8", "1.8.7", "1.8.6", "1.8.5", "1.8.4", "1.8.3", "1.8.2", "1.8.1", "1.8",
                    "1.7.10", "1.7.9", "1.7.8", "1.7.6", "1.7.5", "1.7.4", "1.7.2",
                    "1.6.4", "1.6.2", "1.6.1",
                    "1.5.2", "1.5.1", "1.5",
                    "1.4.7", "1.4.6", "1.4.5", "1.4.4", "1.4.3", "1.4.2",
                    "1.3.2", "1.3.1",
                    "1.2.5", "1.2.4", "1.2.3", "1.2.2", "1.2.1",
                    "1.1", "1.0"
                ];
                foreach ($versions as $v) {
                    $selected = ($postVersion === $v) ? 'selected' : '';
                    echo "<option value=\"$v\" $selected>$v</option>";
                }
                ?>
            </select>
        </div>



                    $properties = <<<EOF
enable-jmx-monitoring=false
rcon.port=25575
level-seed=
gamemode=survival
enable-command-block=false
enable-query=false
generator-settings=
level-name=world
motd=$postServerName $postVersion!
query.port=25565
pvp=true
generate-structures=true
difficulty=easy
network-compression-threshold=256
max-tick-time=60000
use-native-transport=true
max-players=50
online-mode=true
enable-status=true
allow-flight=false
broadcast-rcon-to-ops=true
view-distance=10
max-build-height=256
server-ip=0.0.0.0
allow-nether=true
server-port=25565
enable-rcon=false
sync-chunk-writes=true
op-permission-level=4
prevent-proxy-connections=false
resource-pack=
entity-broadcast-range-percentage=100
rcon.password=
player-idle-timeout=0
debug=false
force-gamemode=false
rate-limit=0
hardcore=false
white-list=false
broadcast-console-to-ops=true
spawn-npcs=true
spawn-animals=true
snooper-enabled=true
function-permission-level=2
text-filtering-config=
spawn-monsters=true
enforce-whitelist=false
resource-pack-sha1=
spawn-protection=16
max-world-size=29999984
EOF;