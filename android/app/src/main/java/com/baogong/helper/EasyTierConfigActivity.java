package com.baogong.helper;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.net.VpnService;
import android.os.Bundle;
import android.text.InputType;
import android.util.TypedValue;
import android.view.Gravity;
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

/** V4 EasyTier 配置页：粘贴 TOML → Keystore 加密保存 → 可选立即连接。 */
public class EasyTierConfigActivity extends Activity {

    private static final int REQ_VPN = 2101;
    private static final int MATCH = ViewGroup.LayoutParams.MATCH_PARENT;
    private static final int WRAP = ViewGroup.LayoutParams.WRAP_CONTENT;

    private EditText input;
    private EasyTierManager manager;
    private boolean connectAfterSave;

    private static final String TEMPLATE =
            "# 实例名（必填，唯一）\n"
            + "inst_name = \"baogong_phone\"\n"
            + "# 网络名（必填）\n"
            + "network = \"my_network\"\n"
            + "# 网络密钥（与服务端/其他节点一致）\n"
            + "network_secret = \"change_me\"\n"
            + "# 虚拟 IP（必填，如 10.144.144.1/24）\n"
            + "ipv4 = \"10.144.144.2/24\"\n"
            + "# 对等节点\n"
            + "peers = [\"tcp://node1.example.com:11010\"]\n"
            + "# 走隧道的内网网段（报工服务器所在网段）\n"
            + "proxy_networks = [\"192.168.1.0/24\"]\n";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        manager = new EasyTierManager(this);
        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(0xFFFFFFFF);
        root.addView(header());

        ScrollView scroll = new ScrollView(this);
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setPadding(dp(20), dp(18), dp(20), dp(28));

        TextView tip = new TextView(this);
        tip.setText("填入 EasyTier TOML 配置。network_secret 与虚拟 IP 会被加密保存在本机，"
                + "不会明文显示。首次连接需确认系统 VPN 授权。\n"
                + "proxy_networks 建议只填报工服务器网段，不要把本 App 也代理进去。");
        tip.setTextSize(14);
        tip.setTextColor(0xFF555555);
        box.addView(tip, lp(WRAP));

        input = new EditText(this);
        input.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_MULTI_LINE);
        input.setGravity(Gravity.TOP | Gravity.START);
        input.setMinLines(12);
        input.setTextSize(13);
        input.setBackgroundColor(0xFFF5F5F5);
        input.setPadding(dp(12), dp(12), dp(12), dp(12));
        box.addView(input, lp(MATCH));

        Button template = button("填入示例模板");
        template.setOnClickListener(v -> input.setText(TEMPLATE));
        box.addView(template, lp(MATCH));

        Button save = button("保存配置");
        save.setOnClickListener(v -> {
            if (saveConfig()) {
                Toast.makeText(this, "配置已加密保存", Toast.LENGTH_SHORT).show();
                finish();
            }
        });
        box.addView(save, lp(MATCH));

        Button saveConnect = button("保存并连接");
        saveConnect.setOnClickListener(v -> {
            if (!saveConfig()) {
                return;
            }
            connectAfterSave = true;
            requestVpnThenConnect();
        });
        box.addView(saveConnect, lp(MATCH));

        // 载入已保存配置
        try {
            String saved = EasyTierConfigStore.load(this);
            if (saved != null) {
                input.setText(saved);
            }
        } catch (Exception e) {
            Toast.makeText(this, "读取已存配置失败", Toast.LENGTH_SHORT).show();
        }

        scroll.addView(box, lp(MATCH));
        root.addView(scroll, lp(MATCH, 1));
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
            root.setPadding(0, bars.top, 0, bars.bottom);
            return insets;
        });
        setContentView(root);
    }

    private boolean saveConfig() {
        String toml = input.getText().toString().trim();
        if (toml.isEmpty()) {
            Toast.makeText(this, "配置不能为空", Toast.LENGTH_SHORT).show();
            return false;
        }
        try {
            EasyTierConfigStore.save(this, toml);
            EasyTierConfigStore.setEnabled(this, true);
            return true;
        } catch (Exception e) {
            Toast.makeText(this, "保存失败: " + e.getMessage(), Toast.LENGTH_LONG).show();
            return false;
        }
    }

    private void requestVpnThenConnect() {
        Intent prepare = manager.prepare();
        if (prepare == null) {
            doConnect();
        } else {
            startActivityForResult(prepare, REQ_VPN);
        }
    }

    private void doConnect() {
        String toml = input.getText().toString().trim();
        new Thread(() -> {
            try {
                manager.connect(toml, true);
                runOnUiThread(() -> {
                    Toast.makeText(this, "EasyTier 连接已发起", Toast.LENGTH_SHORT).show();
                    finish();
                });
            } catch (Throwable t) {
                String msg = t.getMessage() == null ? "未知错误" : t.getMessage();
                runOnUiThread(() -> new AlertDialog.Builder(this)
                        .setTitle("连接失败")
                        .setMessage(msg)
                        .setPositiveButton("确定", null)
                        .show());
            }
        }, "easytier-connect").start();
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_VPN) {
            if (resultCode == RESULT_OK) {
                doConnect();
            } else {
                Toast.makeText(this, "未授予 VPN 权限，连接取消", Toast.LENGTH_SHORT).show();
            }
        }
    }

    // ---- UI 辅助 ----
    private TextView header() {
        TextView h = new TextView(this);
        h.setText("EasyTier 配置");
        h.setTextColor(0xFFFFFFFF);
        h.setTextSize(18);
        h.setGravity(Gravity.CENTER_VERTICAL);
        h.setBackgroundColor(0xFF1F6FEB);
        h.setPadding(dp(20), 0, dp(20), 0);
        return h;
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
