package com.baogong.helper;

import android.content.Context;
import android.content.Intent;
import android.net.VpnService;

import com.easytier.jni.EasyTierJni;

import java.util.ArrayList;
import java.util.List;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * V4 EasyTier 管理器：负责配置校验、实例启停、状态读取，以及与 WireGuard 的互斥切换。
 *
 * 与 WireGuard 互斥：Android 同一时刻通常只有一个 VpnService 隧道生效，因此
 * - 启动 EasyTier 前先断开 WireGuard；
 * - 启动 WireGuard 前先停止 EasyTier（在 NetworkSettingsActivity 里调用 stop）。
 */
public final class EasyTierManager {

    private final Context app;

    public EasyTierManager(Context context) {
        app = context.getApplicationContext();
    }

    /** 返回 null 表示已获得 VPN 授权；否则交给 startActivityForResult */
    public Intent prepare() {
        return VpnService.prepare(app);
    }

    /** 校验配置（会调用 native parseConfig，需 so 就绪） */
    public void validate(String toml) {
        EasyTierJni.parseConfig(toml);
    }

    /**
     * 启动 EasyTier：先解析配置拿到实例名/虚拟IP/网段，runNetworkInstance，再启动 VpnService 建 TUN。
     * 注意：必须先经过 prepare() 授权。
     */
    public void connect(String toml, boolean disallowSelf) throws Exception {
        String instName = extractScalar(toml, "inst_name");
        String ipv4 = extractScalar(toml, "ipv4");
        if (instName == null || instName.isEmpty()) {
            throw new IllegalArgumentException("配置缺少 inst_name");
        }
        if (ipv4 == null || ipv4.isEmpty()) {
            throw new IllegalArgumentException("配置缺少 ipv4（虚拟 IP，如 10.144.144.1/24）");
        }

        // 停掉可能存在的同名旧实例，避免重复
        try {
            EasyTierJni.deleteNetworkInstance(instName);
        } catch (Throwable ignored) {
        }

        int r = EasyTierJni.runNetworkInstance(toml);
        if (r != 0) {
            throw new IllegalStateException("启动实例失败: " + EasyTierJni.getLastError());
        }

        List<String> cidrs = extractArray(toml, "proxy_networks");
        Intent i = new Intent(app, EasyTierVpnService.class);
        i.putExtra(EasyTierVpnService.EXTRA_INSTANCE, instName);
        i.putExtra(EasyTierVpnService.EXTRA_IPV4, ipv4);
        i.putStringArrayListExtra(EasyTierVpnService.EXTRA_CIDRS, new ArrayList<>(cidrs));
        i.putExtra(EasyTierVpnService.EXTRA_DISALLOW_SELF, disallowSelf);
        app.startForegroundService(i);
    }

    /** 停止所有 EasyTier 实例并关闭 VPN 服务 */
    public void disconnect() {
        try {
            EasyTierJni.retainNetworkInstance(null); // null = 全部停止
        } catch (Throwable ignored) {
        }
        app.stopService(new Intent(app, EasyTierVpnService.class));
    }

    /** 读取运行中实例信息 JSON（可能为空） */
    public String statusJson() {
        try {
            return EasyTierJni.collectNetworkInfos(16);
        } catch (Throwable t) {
            return null;
        }
    }

    /** 是否有运行中的 EasyTier 实例 */
    public boolean isRunning() {
        String json = statusJson();
        return json != null && json.contains("instance");
    }

    // ---- 轻量 TOML 解析：只取本项目需要的标量/数组，避免引入第三方库 ----

    private static String extractScalar(String toml, String key) {
        Pattern p = Pattern.compile("(?m)^\\s*" + Pattern.quote(key) + "\\s*=\\s*\"([^\"]*)\"");
        Matcher m = p.matcher(toml);
        return m.find() ? m.group(1) : null;
    }

    private static List<String> extractArray(String toml, String key) {
        List<String> out = new ArrayList<>();
        Pattern p = Pattern.compile("(?m)^\\s*" + Pattern.quote(key)
                + "\\s*=\\s*\\[([^\\]]*)\\]");
        Matcher m = p.matcher(toml);
        if (m.find()) {
            Matcher items = Pattern.compile("\"([^\"]*)\"").matcher(m.group(1));
            while (items.find()) {
                out.add(items.group(1));
            }
        }
        return out;
    }
}
