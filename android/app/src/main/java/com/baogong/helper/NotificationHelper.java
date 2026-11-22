package com.baogong.helper;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Context;
import android.content.pm.PackageManager;
import android.os.Build;

import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;

/**
 * V3 通知渠道统一管理。
 * Android 8+ 必须先建渠道，Android 13+ 还需要 POST_NOTIFICATIONS 运行时权限；
 * 未授权时所有通知静默跳过，不影响下载 / VPN 主流程。
 */
public final class NotificationHelper {

    public static final String CH_DOWNLOAD = "bg_download";
    public static final String CH_VPN = "bg_vpn";
    public static final String CH_PUSH = "bg_push";

    private static int seq = 1000;

    private NotificationHelper() {
    }

    public static synchronized int nextId() {
        return seq++;
    }

    public static void createChannels(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager nm = context.getSystemService(NotificationManager.class);
        if (nm == null) {
            return;
        }
        create(nm, CH_DOWNLOAD, "文件下载", "下载进度与完成提醒", NotificationManager.IMPORTANCE_LOW);
        create(nm, CH_VPN, "VPN 隧道", "隧道连接状态", NotificationManager.IMPORTANCE_LOW);
        create(nm, CH_PUSH, "报工消息", "服务端推送的通知", NotificationManager.IMPORTANCE_DEFAULT);
    }

    private static void create(NotificationManager nm, String id, String name,
                               String desc, int importance) {
        NotificationChannel channel = new NotificationChannel(id, name, importance);
        channel.setDescription(desc);
        nm.createNotificationChannel(channel);
    }

    public static boolean canNotify(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            return true;
        }
        return ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS)
                == PackageManager.PERMISSION_GRANTED;
    }

    /**
     * @param max 大于 0 时显示进度条；等于 0 时为不确定进度
     */
    public static void show(Context context, String channel, int id, String title, String text,
                            boolean ongoing, int progress, int max) {
        if (!canNotify(context)) {
            return;
        }
        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, channel)
                .setSmallIcon(android.R.drawable.stat_sys_download)
                .setContentTitle(title)
                .setContentText(text)
                .setOngoing(ongoing)
                .setOnlyAlertOnce(true);
        if (max > 0) {
            builder.setProgress(max, progress, false);
        } else {
            builder.setProgress(0, 0, true);
        }
        try {
            NotificationManagerCompat.from(context).notify(id, builder.build());
        } catch (SecurityException ignored) {
            // 用户撤销通知权限时忽略
        }
    }

    public static void finish(Context context, int id, String channel, String title, String text) {
        if (!canNotify(context)) {
            return;
        }
        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, channel)
                .setSmallIcon(android.R.drawable.stat_sys_download_done)
                .setContentTitle(title)
                .setContentText(text)
                .setAutoCancel(true);
        try {
            NotificationManagerCompat.from(context).notify(id, builder.build());
        } catch (SecurityException ignored) {
        }
    }

    public static void cancel(Context context, int id) {
        try {
            NotificationManagerCompat.from(context).cancel(id);
        } catch (SecurityException ignored) {
        }
    }
}
