package com.baogong.helper;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.os.Bundle;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;

/**
 * V3 内嵌 WireGuard 设置页（对应开发文档第 10 节）。
 *
 * 交互：
 * - 用户粘贴标准 WireGuard .conf 文本 → 「保存配置」用 Android Keystore 加密落盘（私钥不回显）；
 * - 「连接」先调用 VpnService.prepare() 让用户确认系统 VPN 授权，无法静默；
 * - 「断开」下线隧道；「清空配置」删除本机密钥材料，等效于吊销该设备。
 *
 * 注意：本版本下载已改为 App 内实现，即使隧道配置了 IncludedApplications，
 * 下载流量也会随本 App 走隧道，不会出现文档提到的「系统 DownloadManager 下载失败」问题。
 */
public class VpnSettingsActivity extends Activity {

    private static final int REQ_VPN = 2001;
    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    private EditText input;
    private TextView status;
    private WireGuardManager vpn;
    private String pendingConf;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        vpn = new WireGuardManager(this);

        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(0xFFFFFFFF);

        TextView header = new TextView(this);
        header.setText("VPN 隧道设置");
        header.setTextColor(0xFFFFFFFF);
        header.setTextSize(18);
        header.setGravity(Gravity.CENTER_VERTICAL);
        header.setBackgroundColor(0xFF1F6FEB);
        header.setPadding(dp(20), 0, dp(20), 0);
        root.addView(header, new LinearLayout.LayoutParams(MATCH, dp(56)));

        ScrollView scroll = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(20), dp(20), dp(28));

        TextView intro = new TextView(this);
        intro.setText("外网访问时，可先连接 WireGuard 隧道再加载内网报工地址，"
                + "无需把 Web 端口暴露到公网。\n\n"
                + "配置由你在本页导入，使用 Android Keystore 加密保存，"
                + "私钥不会硬编码在 App 里，也不会明文显示。\n\n"
                + "注意事项：\n"
                + "· 首次连接必须由你确认系统 VPN 授权；\n"
                + "· AllowedIPs 只写内网网段或服务器 IP/32，不要写 0.0.0.0/0；\n"
                + "· 每台设备用独立密钥，「清空配置」即可吊销该设备；\n"
                + "· 本版本已改用 App 内下载，隧道内下载 CSV / 备份可用。");
        intro.setTextColor(0xFF666666);
        intro.setTextSize(13);
        box.addView(intro);

        TextView label = new TextView(this);
        label.setText("WireGuard 配置（.conf 内容）");
        label.setTextColor(0xFF333333);
        label.setTextSize(14);
        LinearLayout.LayoutParams labelLp = new LinearLayout.LayoutParams(WRAP, WRAP);
        labelLp.topMargin = dp(20);
        box.addView(label, labelLp);

        input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        input.setSingleLine(false);
        input.setMinLines(8);
        input.setGravity(Gravity.TOP | Gravity.START);
        input.setTextSize(12);
        input.setHint("[Interface]\nPrivateKey = ...\nAddress = 10.0.0.2/32\nDNS = 1.1.1.1\n\n"
                + "[Peer]\nPublicKey = ...\nEndpoint = example.com:51820\n"
                + "AllowedIPs = 192.168.1.0/24\nPersistentKeepalive = 25");
        LinearLayout.LayoutParams inputLp = new LinearLayout.LayoutParams(MATCH, WRAP);
        inputLp.topMargin = dp(8);
        box.addView(input, inputLp);

        box.addView(makeRow(
                makeButton("保存配置", v -> saveConfig()),
                makeButton("清空配置", v -> confirmClear())));
        box.addView(makeRow(
                makeButton("连接", v -> connect()),
                makeButton("断开", v -> disconnect())));

        status = new TextView(this);
        status.setText("尚未导入配置");
        status.setTextColor(0xFF333333);
        status.setTextSize(14);
        LinearLayout.LayoutParams statusLp = new LinearLayout.LayoutParams(MATCH, WRAP);
        statusLp.topMargin = dp(20);
        box.addView(status, statusLp);

        scroll.addView(box, new ScrollView.LayoutParams(MATCH, WRAP));
        root.addView(scroll, new LinearLayout.LayoutParams(MATCH, 0, 1f));
        setContentView(root);

        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
            return insets;
        });

        loadSaved();
        refreshStatus();
    }

    private LinearLayout makeRow(Button left, Button right) {
        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        LinearLayout.LayoutParams rowLp = new LinearLayout.LayoutParams(MATCH, WRAP);
        rowLp.topMargin = dp(10);
        row.setLayoutParams(rowLp);

        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(0, WRAP, 1f);
        lp.rightMargin = dp(6);
        row.addView(left, lp);
        lp = new LinearLayout.LayoutParams(0, WRAP, 1f);
        lp.leftMargin = dp(6);
        row.addView(right, lp);
        return row;
    }

    private Button makeButton(String text, View.OnClickListener listener) {
        Button button = new Button(this);
        button.setText(text);
        button.setOnClickListener(listener);
        return button;
    }

    private void loadSaved() {
        try {
            String saved = VpnConfigStore.load(this);
            if (saved != null && !saved.isEmpty()) {
                // 不回显私钥，只提示已存在配置
                input.setHint("已导入加密配置（私钥不回显）。粘贴新配置可覆盖，留空则使用已保存的配置。");
                setStatus("已导入加密配置，可直接点「连接」");
            } else {
                setStatus("尚未导入配置");
            }
        } catch (Exception e) {
            setStatus("读取配置失败：" + e.getMessage());
        }
    }

    private void saveConfig() {
        String conf = input.getText().toString().trim();
        if (conf.isEmpty()) {
            toast("请先粘贴 WireGuard 配置");
            return;
        }
        try {
            VpnConfigStore.save(this, conf);
            input.setText("");
            input.setHint("已导入加密配置（私钥不回显）。粘贴新配置可覆盖，留空则使用已保存的配置。");
            setStatus("配置已加密保存");
            toast("配置已加密保存到本机");
        } catch (Exception e) {
            setStatus("保存失败：" + e.getMessage());
            toast("保存失败：" + e.getMessage());
        }
    }

    private void connect() {
        String typed = input.getText().toString().trim();
        final String conf;
        if (!typed.isEmpty()) {
            conf = typed;
            try {
                VpnConfigStore.save(this, conf);
            } catch (Exception e) {
                setStatus("配置保存失败：" + e.getMessage());
                return;
            }
        } else {
            try {
                conf = VpnConfigStore.load(this);
            } catch (Exception e) {
                setStatus("读取配置失败：" + e.getMessage());
                return;
            }
        }
        if (conf == null || conf.isEmpty()) {
            toast("请先粘贴并保存 WireGuard 配置");
            return;
        }

        Intent prepare = vpn.prepare();
        if (prepare != null) {
            pendingConf = conf;
            try {
                startActivityForResult(prepare, REQ_VPN);
            } catch (Exception e) {
                pendingConf = null;
                setStatus("无法发起 VPN 授权：" + e.getMessage());
            }
        } else {
            connectNow(conf);
        }
    }

    private void connectNow(String conf) {
        setStatus("正在建立隧道…");
        new Thread(() -> {
            try {
                vpn.connect(conf);
                runOnUiThread(() -> {
                    refreshStatus();
                    NotificationHelper.show(VpnSettingsActivity.this,
                            NotificationHelper.CH_VPN, NotificationHelper.nextId(),
                            "VPN 隧道已连接", "报工助手隧道正在运行", true, 0, 0);
                    toast("VPN 已连接，可返回主界面重新加载");
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    setStatus("连接失败：" + e.getMessage());
                    toast("连接失败：" + e.getMessage());
                });
            }
        }).start();
    }

    private void disconnect() {
        new Thread(() -> {
            try {
                vpn.disconnect();
                runOnUiThread(() -> {
                    refreshStatus();
                    NotificationHelper.cancel(VpnSettingsActivity.this, 0);
                    toast("VPN 已断开");
                });
            } catch (Exception e) {
                runOnUiThread(() -> setStatus("断开失败：" + e.getMessage()));
            }
        }).start();
    }

    private void confirmClear() {
        new AlertDialog.Builder(this)
                .setTitle("清空配置")
                .setMessage("将删除本机保存的 WireGuard 配置与密钥材料（等效于吊销本设备）。\n"
                        + "确定继续吗？")
                .setPositiveButton("确定", (dialog, which) -> {
                    try {
                        vpn.disconnect();
                    } catch (Exception ignored) {
                    }
                    VpnConfigStore.clear(this);
                    input.setText("");
                    input.setHint("[Interface]\nPrivateKey = ...");
                    setStatus("配置已清空");
                    toast("配置已清空");
                })
                .setNegativeButton("取消", null)
                .show();
    }

    private void refreshStatus() {
        try {
            setStatus("隧道状态：" + vpn.getState());
        } catch (Exception e) {
            setStatus("隧道状态未知：" + e.getMessage());
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQ_VPN) {
            if (resultCode == RESULT_OK && pendingConf != null) {
                connectNow(pendingConf);
            } else {
                setStatus("VPN 授权被取消，未建立隧道");
            }
            pendingConf = null;
            return;
        }
        super.onActivityResult(requestCode, resultCode, data);
    }

    private void setStatus(String text) {
        if (status != null) {
            status.setText(text);
        }
    }

    private void toast(String text) {
        Toast.makeText(this, text, Toast.LENGTH_LONG).show();
    }

    private int dp(float value) {
        return (int) TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, value,
                getResources().getDisplayMetrics());
    }
}
