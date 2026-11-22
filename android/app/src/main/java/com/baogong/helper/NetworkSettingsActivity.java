package com.baogong.helper;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.net.VpnService;
import android.os.Bundle;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;

import com.wireguard.android.backend.Tunnel;

/**
 * V4 网络设置页：WireGuard 与 EasyTier 互斥切换。
 *
 * Android 同一时刻通常只有一个 VpnService 隧道生效，因此两种模式严格互斥：
 * - 选 WireGuard 并连接 → 先停 EasyTier；
 * - 选 EasyTier 并连接 → 先停 WireGuard。
 * 每种模式各自保存配置（Keystore 加密），切换不影响另一方的配置。
 */
public class NetworkSettingsActivity extends Activity {

    private static final int REQ_WG_VPN = 2001;
    private static final int REQ_ET_VPN = 2101;
    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    private TextView statusText;
    private WireGuardManager wg;
    private EasyTierManager et;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        wg = new WireGuardManager(this);
        et = new EasyTierManager(this);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(0xFFFFFFFF);

        TextView header = new TextView(this);
        header.setText("网络设置");
        header.setTextColor(0xFFFFFFFF);
        header.setTextSize(18);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setBackgroundColor(0xFF1F6FEB);
        header.setPadding(dp(20), 0, dp(20), 0);
        root.addView(header, new LinearLayout.LayoutParams(MATCH, dp(56)));

        ScrollView scroll = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(18), dp(20), dp(28));

        TextView intro = new TextView(this);
        intro.setText("两种网络模式互斥，同一时间只能连接一种。\n"
                + "· WireGuard：连回固定服务器（如车间报工服务器）；\n"
                + "· EasyTier：多节点去中心化组网，适合无固定公网地址的场景。");
        intro.setTextSize(14);
        intro.setTextColor(0xFF555555);
        box.addView(intro, lp(WRAP));

        statusText = new TextView(this);
        statusText.setTextSize(15);
        statusText.setTextColor(0xFF1F6FEB);
        statusText.setPadding(0, dp(14), 0, dp(14));
        box.addView(statusText, lp(WRAP));

        // WireGuard 区
        box.addView(sectionTitle("① WireGuard"));
        Button wgConfig = button("配置 WireGuard");
        wgConfig.setOnClickListener(v -> startActivity(new Intent(this, VpnSettingsActivity.class)));
        box.addView(wgConfig, lp(MATCH));
        Button wgConnect = button("连接 WireGuard");
        wgConnect.setOnClickListener(v -> connectWg());
        box.addView(wgConnect, lp(MATCH));
        Button wgDisconnect = button("断开 WireGuard");
        wgDisconnect.setOnClickListener(v -> {
            try {
                wg.disconnect();
                refreshStatus();
                Toast.makeText(this, "WireGuard 已断开", Toast.LENGTH_SHORT).show();
            } catch (Exception e) {
                Toast.makeText(this, "断开失败: " + e.getMessage(), Toast.LENGTH_SHORT).show();
            }
        });
        box.addView(wgDisconnect, lp(MATCH));

        // EasyTier 区
        box.addView(sectionTitle("② EasyTier 组网"));
        Button etConfig = button("配置 EasyTier");
        etConfig.setOnClickListener(v -> startActivity(new Intent(this, EasyTierConfigActivity.class)));
        box.addView(etConfig, lp(MATCH));
        Button etConnect = button("连接 EasyTier");
        etConnect.setOnClickListener(v -> connectEt());
        box.addView(etConnect, lp(MATCH));
        Button etDisconnect = button("断开 EasyTier");
        etDisconnect.setOnClickListener(v -> {
            et.disconnect();
            refreshStatus();
            Toast.makeText(this, "EasyTier 已断开", Toast.LENGTH_SHORT).show();
        });
        box.addView(etDisconnect, lp(MATCH));

        TextView note = new TextView(this);
        note.setText("提示：连接任一模式前，请先完成对应配置。首次连接需确认系统 VPN 授权。");
        note.setTextSize(13);
        note.setTextColor(0xFF888888);
        note.setPadding(0, dp(16), 0, 0);
        box.addView(note, lp(WRAP));

        scroll.addView(box, lp(MATCH));
        root.addView(scroll, lp(MATCH, 1));
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            root.setPadding(0, bars.top, 0, bars.bottom);
            return insets;
        });
        setContentView(root);
    }

    @Override
    protected void onResume() {
        super.onResume();
        refreshStatus();
    }

    private void refreshStatus() {
        StringBuilder sb = new StringBuilder("当前状态：\n");
        try {
            Tunnel.State wgState = wg.getState();
            sb.append("WireGuard：").append(wgState == Tunnel.State.UP ? "已连接" : "未连接");
        } catch (Throwable t) {
            sb.append("WireGuard：未连接");
        }
        sb.append("\nEasyTier：").append(et.isRunning() ? "已连接" : "未连接");
        statusText.setText(sb.toString());
    }

    private void connectWg() {
        String conf;
        try {
            conf = VpnConfigStore.load(this);
        } catch (Exception e) {
            Toast.makeText(this, "读取 WireGuard 配置失败", Toast.LENGTH_SHORT).show();
            return;
        }
        if (conf == null) {
            Toast.makeText(this, "请先配置 WireGuard", Toast.LENGTH_SHORT).show();
            return;
        }
        Intent prepare = wg.prepare();
        if (prepare != null) {
            pendingWg = true;
            startActivityForResult(prepare, REQ_WG_VPN);
            return;
        }
        doWgConnect(conf);
    }

    private boolean pendingWg;
    private boolean pendingEt;

    private void connectEt() {
        String toml;
        try {
            toml = EasyTierConfigStore.load(this);
        } catch (Exception e) {
            Toast.makeText(this, "读取 EasyTier 配置失败", Toast.LENGTH_SHORT).show();
            return;
        }
        if (toml == null) {
            Toast.makeText(this, "请先配置 EasyTier", Toast.LENGTH_SHORT).show();
            return;
        }
        Intent prepare = et.prepare();
        if (prepare != null) {
            pendingEt = true;
            startActivityForResult(prepare, REQ_ET_VPN);
            return;
        }
        doEtConnect(toml);
    }

    private void doWgConnect(String conf) {
        // 互斥：先停 EasyTier
        et.disconnect();
        new Thread(() -> {
            try {
                wg.connect(conf);
                runOnUiThread(() -> {
                    refreshStatus();
                    Toast.makeText(this, "WireGuard 已连接", Toast.LENGTH_SHORT).show();
                });
            } catch (Throwable t) {
                String msg = t.getMessage() == null ? "未知错误" : t.getMessage();
                runOnUiThread(() -> showError("WireGuard 连接失败", msg));
            }
        }, "wg-connect").start();
    }

    private void doEtConnect(String toml) {
        // 互斥：先停 WireGuard
        try {
            wg.disconnect();
        } catch (Throwable ignored) {
        }
        new Thread(() -> {
            try {
                et.connect(toml, true);
                runOnUiThread(() -> {
                    refreshStatus();
                    Toast.makeText(this, "EasyTier 连接已发起", Toast.LENGTH_SHORT).show();
                });
            } catch (Throwable t) {
                String msg = t.getMessage() == null ? "未知错误" : t.getMessage();
                runOnUiThread(() -> showError("EasyTier 连接失败", msg));
            }
        }, "et-connect").start();
    }

    private void showError(String title, String msg) {
        new AlertDialog.Builder(this).setTitle(title).setMessage(msg)
                .setPositiveButton("确定", null).show();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (resultCode != RESULT_OK) {
            Toast.makeText(this, "未授予 VPN 权限，连接取消", Toast.LENGTH_SHORT).show();
            return;
        }
        if (requestCode == REQ_WG_VPN && pendingWg) {
            pendingWg = false;
            try {
                doWgConnect(VpnConfigStore.load(this));
            } catch (Exception ignored) {
            }
        } else if (requestCode == REQ_ET_VPN && pendingEt) {
            pendingEt = false;
            try {
                doEtConnect(EasyTierConfigStore.load(this));
            } catch (Exception ignored) {
            }
        }
    }

    // ---- UI 辅助 ----
    private TextView sectionTitle(String text) {
        TextView t = new TextView(this);
        t.setText(text);
        t.setTextSize(16);
        t.setTextColor(0xFF1F6FEB);
        t.setPadding(0, dp(18), 0, dp(8));
        return t;
    }

    private Button button(String text) {
        Button b = new Button(this);
        b.setText(text);
        b.setAllCaps(false);
        return b;
    }

    private LinearLayout.LayoutParams lp(int w) {
        return new LinearLayout.LayoutParams(w, WRAP);
    }

    private LinearLayout.LayoutParams lp(int w, int weight) {
        return new LinearLayout.LayoutParams(w, 0, weight);
    }

    private int dp(int value) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, value,
                getResources().getDisplayMetrics());
    }
}
