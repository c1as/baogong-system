package com.baogong.helper;

import android.content.Context;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.NetworkRequest;

/**
 * V3 网络状态监听，用于「离线友好提示页」。
 * 用 registerNetworkCallback 而不是 registerDefaultNetworkCallback，兼容 minSdk 24。
 */
public final class NetworkMonitor {

    public interface Callback {
        void onChanged(boolean online);
    }

    private final ConnectivityManager manager;
    private final ConnectivityManager.NetworkCallback networkCallback;
    private boolean registered;

    private NetworkMonitor(Context context, final Callback callback) {
        manager = (ConnectivityManager) context.getApplicationContext()
                .getSystemService(Context.CONNECTIVITY_SERVICE);
        networkCallback = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(Network network) {
                callback.onChanged(true);
            }

            @Override
            public void onLost(Network network) {
                callback.onChanged(false);
            }

            @Override
            public void onCapabilitiesChanged(Network network, NetworkCapabilities caps) {
                callback.onChanged(caps != null
                        && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET));
            }
        };
    }

    /** 同步判断当前是否有可用网络 */
    public static boolean isOnline(Context context) {
        ConnectivityManager cm = (ConnectivityManager) context.getApplicationContext()
                .getSystemService(Context.CONNECTIVITY_SERVICE);
        if (cm == null) {
            return false;
        }
        Network network = cm.getActiveNetwork();
        if (network == null) {
            return false;
        }
        NetworkCapabilities caps = cm.getNetworkCapabilities(network);
        return caps != null && caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
    }

    public static NetworkMonitor register(Context context, Callback callback) {
        NetworkMonitor monitor = new NetworkMonitor(context, callback);
        if (monitor.manager != null) {
            try {
                NetworkRequest request = new NetworkRequest.Builder()
                        .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                        .build();
                monitor.manager.registerNetworkCallback(request, monitor.networkCallback);
                monitor.registered = true;
            } catch (Exception ignored) {
                // 少数机型可能抛异常，退化为只靠 onReceivedError 显示错误页
            }
        }
        return monitor;
    }

    public void unregister() {
        if (registered && manager != null) {
            try {
                manager.unregisterNetworkCallback(networkCallback);
            } catch (Exception ignored) {
            }
            registered = false;
        }
    }
}
