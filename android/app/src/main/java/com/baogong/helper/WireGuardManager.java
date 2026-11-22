package com.baogong.helper;

import android.content.Context;
import android.content.Intent;
import android.net.VpnService;

import com.wireguard.android.backend.GoBackend;
import com.wireguard.android.backend.Tunnel;
import com.wireguard.config.Config;

import java.io.ByteArrayInputStream;
import java.nio.charset.StandardCharsets;

/**
 * V3 内嵌 WireGuard 封装（对应开发文档第 10 节）。
 *
 * 使用要点：
 * - 底层 VpnService 由 tunnel 库的 GoBackend$VpnService 提供，其 manifest 会自动合并；
 * - 首次连接必须先调用 prepare() 拿到授权 Intent 并由用户确认，无法静默建立隧道；
 * - 配置文本由用户在设置页导入，经 VpnConfigStore 加密保存，私钥不硬编码；
 * - AllowedIPs 请只写内网网段或服务器 /32，不要写 0.0.0.0/0。
 */
public final class WireGuardManager {

    private final Context app;
    private final GoBackend backend;
    private final Tunnel tunnel;

    public WireGuardManager(Context context) {
        app = context.getApplicationContext();
        backend = new GoBackend(app);
        tunnel = new BaogongTunnel();
    }

    /** 返回 null 表示已获得 VPN 授权；否则把该 Intent 交给 startActivityForResult */
    public Intent prepare() {
        return VpnService.prepare(app);
    }

    public void connect(String confText) throws Exception {
        Config config = Config.parse(
                new ByteArrayInputStream(confText.getBytes(StandardCharsets.UTF_8)));
        backend.setState(tunnel, Tunnel.State.UP, config);
    }

    public void disconnect() throws Exception {
        backend.setState(tunnel, Tunnel.State.DOWN, null);
    }

    public Tunnel.State getState() {
        return backend.getState(tunnel);
    }

    private static final class BaogongTunnel implements Tunnel {
        @Override
        public String getName() {
            return "baogong";
        }

        @Override
        public void onStateChange(State state) {
            // 状态由 GoBackend 维护，这里无需额外处理
        }
    }
}
