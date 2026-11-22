package com.baogong.helper;

import android.app.Service;
import android.content.Intent;
import android.net.VpnService;
import android.os.IBinder;
import android.os.ParcelFileDescriptor;
import android.util.Log;

import com.easytier.jni.EasyTierJni;

import java.util.ArrayList;

/**
 * V4 EasyTier 的 Android VPN 服务：建立系统 TUN 虚拟网卡，把 fd 交给 EasyTier native 层。
 * 纯 Java 复刻官方 easytier-android-jni/kotlin/.../EasyTierVpnService.t.kt 的逻辑。
 *
 * 启动参数（Intent extras）：
 * - instance_name：EasyTier 实例名（来自 TOML 的 inst_name），必需
 * - ipv4_address：虚拟 IP，如 "10.144.144.1/24"，必需
 * - proxy_cidrs：需要走隧道的网段列表
 * - disallow_self：是否把本 App 排除在隧道外（避免 WebView 访问服务器也被代理）
 */
public final class EasyTierVpnService extends VpnService {

    public static final String EXTRA_INSTANCE = "instance_name";
    public static final String EXTRA_IPV4 = "ipv4_address";
    public static final String EXTRA_CIDRS = "proxy_cidrs";
    public static final String EXTRA_DISALLOW_SELF = "disallow_self";
    private static final String TAG = "BgEasyTierVpn";

    private volatile boolean running = false;
    private ParcelFileDescriptor vpnInterface;

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (intent == null) {
            stopSelf();
            return START_NOT_STICKY;
        }
        final String instance = intent.getStringExtra(EXTRA_INSTANCE);
        final String ipv4 = intent.getStringExtra(EXTRA_IPV4);
        final ArrayList<String> cidrs = intent.getStringArrayListExtra(EXTRA_CIDRS);
        final boolean disallowSelf = intent.getBooleanExtra(EXTRA_DISALLOW_SELF, true);

        if (instance == null || ipv4 == null) {
            Log.e(TAG, "缺少必要参数 instance/ipv4");
            stopSelf();
            return START_NOT_STICKY;
        }

        // targetSdk 34：前台服务启动后必须尽快调用 startForeground，否则 ANR
        try {
            NotificationHelper.createChannels(this);
            startForeground(3101,
                    NotificationHelper.buildForegroundNotification(this,
                            NotificationHelper.CH_VPN, "EasyTier 组网", "VPN 隧道运行中"));
        } catch (Throwable t) {
            Log.w(TAG, "startForeground 失败", t);
        }

        new Thread(() -> {
            try {
                setup(instance, ipv4, cidrs == null ? new ArrayList<>() : cidrs, disallowSelf);
            } catch (Throwable t) {
                Log.e(TAG, "VPN 设置失败", t);
                stopSelf();
            }
        }, "easytier-vpn-setup").start();
        return START_STICKY;
    }

    private void setup(String instance, String ipv4, java.util.List<String> cidrs,
                       boolean disallowSelf) throws Exception {
        String ip;
        int prefix;
        int slash = ipv4.indexOf('/');
        if (slash > 0) {
            ip = ipv4.substring(0, slash);
            prefix = Integer.parseInt(ipv4.substring(slash + 1).trim());
        } else {
            ip = ipv4;
            prefix = 24;
        }

        Builder builder = new Builder()
                .setSession("报工助手 EasyTier")
                .addAddress(ip, prefix)
                .addDnsServer("223.5.5.5")
                .addDnsServer("114.114.114.114")
                .setMtu(1420);

        // 把本 App 排除在隧道外：否则 WebView 访问报工服务器会绕进隧道导致连不上。
        // 需要 user 在配置里勾选；默认开启以保证报工网页可达。
        if (disallowSelf) {
            builder.addDisallowedApplication(getPackageName());
        }

        for (String cidr : cidrs) {
            try {
                String[] parts = cidr.trim().split("/");
                if (parts.length == 2) {
                    builder.addRoute(parts[0], Integer.parseInt(parts[1]));
                }
            } catch (Exception e) {
                Log.w(TAG, "忽略无效 CIDR: " + cidr);
            }
        }

        vpnInterface = builder.establish();
        if (vpnInterface == null) {
            Log.e(TAG, "建立 TUN 失败");
            stopSelf();
            return;
        }

        int fd = vpnInterface.getFd();
        int result = EasyTierJni.setTunFd(instance, fd);
        if (result != 0) {
            Log.e(TAG, "setTunFd 失败: " + result + " / " + EasyTierJni.getLastError());
            stopSelf();
            return;
        }
        Log.i(TAG, "TUN 已交给 EasyTier，fd=" + fd);

        running = true;
        while (running && vpnInterface != null) {
            try {
                Thread.sleep(1000);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                break;
            }
        }
        cleanup();
    }

    private void cleanup() {
        running = false;
        if (vpnInterface != null) {
            try {
                vpnInterface.close();
            } catch (Exception ignored) {
            }
            vpnInterface = null;
        }
    }

    @Override
    public void onDestroy() {
        cleanup();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
