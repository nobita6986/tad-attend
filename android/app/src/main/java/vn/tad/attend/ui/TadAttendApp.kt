package vn.tad.attend.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Badge
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Face
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import vn.tad.attend.BuildConfig
import vn.tad.attend.ml.LivenessStage
import java.util.Locale

private val TadColorScheme = lightColorScheme(
    primary = Color(0xFF1955AA),
    onPrimary = Color.White,
    primaryContainer = Color(0xFFE8F1FE),
    onPrimaryContainer = Color(0xFF142946),
    secondary = Color(0xFF087647),
    background = Color(0xFFF3F6FB),
    surface = Color.White,
    error = Color(0xFFBA2B32),
)

@Composable
fun TadAttendApp(viewModel: AttendanceViewModel) {
    val ui by viewModel.ui.collectAsState()
    val context = LocalContext.current
    var selectedTab by rememberSaveable { mutableIntStateOf(0) }
    var hasCameraPermission by remember {
        mutableStateOf(
            ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED,
        )
    }
    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        hasCameraPermission = granted
    }

    LaunchedEffect(Unit) {
        if (!hasCameraPermission) {
            permissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    LaunchedEffect(selectedTab) {
        if (selectedTab != 1 && ui.enrollmentActive) {
            viewModel.cancelEnrollment()
        }
    }

    MaterialTheme(colorScheme = TadColorScheme) {
        Scaffold(
            containerColor = MaterialTheme.colorScheme.background,
            bottomBar = {
                NavigationBar {
                    NavigationBarItem(
                        selected = selectedTab == 0,
                        onClick = { selectedTab = 0 },
                        icon = { Icon(Icons.Default.Face, contentDescription = null) },
                        label = { Text("Chấm công") },
                    )
                    NavigationBarItem(
                        selected = selectedTab == 1,
                        onClick = { selectedTab = 1 },
                        icon = { Icon(Icons.Default.PersonAdd, contentDescription = null) },
                        label = { Text("Đăng ký") },
                    )
                    NavigationBarItem(
                        selected = selectedTab == 2,
                        onClick = { selectedTab = 2 },
                        icon = { Icon(Icons.Default.Settings, contentDescription = null) },
                        label = { Text("Cấu hình") },
                    )
                }
            },
        ) { padding ->
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(padding),
            ) {
                when (selectedTab) {
                    0 -> KioskScreen(
                        ui = ui,
                        hasCameraPermission = hasCameraPermission,
                        onRequestCamera = { permissionLauncher.launch(Manifest.permission.CAMERA) },
                        onFrame = viewModel::onCameraFrame,
                        onReconnect = viewModel::connect,
                        onSyncRoster = viewModel::refreshRoster,
                    )

                    1 -> if (ui.adminUnlocked) {
                        EnrollmentScreen(
                            ui = ui,
                            hasCameraPermission = hasCameraPermission,
                            onRequestCamera = { permissionLauncher.launch(Manifest.permission.CAMERA) },
                            onFrame = viewModel::onCameraFrame,
                            onStart = viewModel::startEnrollment,
                            onCancel = {
                                viewModel.cancelEnrollment()
                                selectedTab = 0
                            },
                        )
                    } else {
                        AdminPinGateScreen(ui = ui, onSubmit = viewModel::unlockAdmin)
                    }

                    2 -> if (ui.adminUnlocked) {
                        SettingsScreen(
                            ui = ui,
                            onSave = { url, token ->
                                viewModel.saveSettingsAndConnect(url, token)
                                selectedTab = 0
                            },
                            onRefreshRoster = viewModel::refreshRoster,
                            onLock = viewModel::lockAdmin,
                        )
                    } else {
                        AdminPinGateScreen(ui = ui, onSubmit = viewModel::unlockAdmin)
                    }
                }
            }
        }
    }
}

@Composable
private fun KioskScreen(
    ui: AttendUiState,
    hasCameraPermission: Boolean,
    onRequestCamera: () -> Unit,
    onFrame: (vn.tad.attend.camera.AnalyzedFrame) -> Unit,
    onReconnect: () -> Unit,
    onSyncRoster: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 16.dp, vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = "TAD ATTEND",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                    color = MaterialTheme.colorScheme.primary,
                )
                val sub = ui.deviceInfo?.let {
                    "${it.name} · ${AttendanceViewModel.directionLabel(it.direction)} · ${ui.rosterCount} NV"
                } ?: "Chưa kết nối thiết bị"
                Text(
                    text = sub,
                    style = MaterialTheme.typography.bodySmall,
                    color = Color(0xFF52657B),
                )
            }
            if (ui.isConnected) {
                IconButton(onClick = onSyncRoster) {
                    Icon(Icons.Default.Refresh, contentDescription = "Đồng bộ nhân viên")
                }
            }
        }

        Spacer(Modifier.height(10.dp))

        val frameBorderColor = when {
            ui.lastReceipt?.reviewState == "accepted" -> Color(0xFF087647)
            ui.isError -> MaterialTheme.colorScheme.error
            ui.livenessStage == LivenessStage.TURN_OPPOSITE_SIDE ||
                ui.livenessStage == LivenessStage.LOOK_STRAIGHT -> Color(0xFF1955AA)
            else -> Color(0xFF94A3B8)
        }

        Box(
            modifier = Modifier
                .fillMaxWidth()
                .weight(1f)
                .clip(RoundedCornerShape(20.dp))
                .background(Color(0xFF0F172A))
                .border(3.dp, frameBorderColor, RoundedCornerShape(20.dp)),
            contentAlignment = Alignment.Center,
        ) {
            if (hasCameraPermission) {
                CameraPreviewView(
                    modifier = Modifier.fillMaxSize(),
                    onFrame = onFrame,
                )
                // Oval face placement guide
                Box(
                    modifier = Modifier
                        .size(width = 220.dp, height = 280.dp)
                        .border(2.dp, Color.White.copy(alpha = 0.65f), CircleShape),
                )
            } else {
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    modifier = Modifier.padding(24.dp),
                ) {
                    Text(
                        text = "Ứng dụng cần quyền Camera trước để chấm công khuôn mặt.",
                        color = Color.White,
                        textAlign = TextAlign.Center,
                    )
                    Spacer(Modifier.height(12.dp))
                    Button(onClick = onRequestCamera) {
                        Text("Cấp quyền Camera")
                    }
                }
            }
        }

        Spacer(Modifier.height(12.dp))

        Card(
            modifier = Modifier.fillMaxWidth(),
            shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            border = BorderStroke(1.dp, Color(0xFFE1E8F1)),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (ui.isConnecting) {
                        CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                        Spacer(Modifier.width(8.dp))
                    } else if (ui.lastReceipt?.reviewState == "accepted") {
                        Icon(Icons.Default.CheckCircle, contentDescription = null, tint = Color(0xFF087647))
                        Spacer(Modifier.width(6.dp))
                    } else if (ui.isError) {
                        Icon(Icons.Default.Warning, contentDescription = null, tint = MaterialTheme.colorScheme.error)
                        Spacer(Modifier.width(6.dp))
                    }
                    Text(
                        text = ui.headline,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = when {
                            ui.lastReceipt?.reviewState == "accepted" -> Color(0xFF087647)
                            ui.isError -> MaterialTheme.colorScheme.error
                            else -> Color(0xFF142946)
                        },
                        textAlign = TextAlign.Center,
                    )
                }
                Spacer(Modifier.height(6.dp))
                Text(
                    text = ui.message,
                    style = MaterialTheme.typography.bodyMedium,
                    color = Color(0xFF334155),
                    textAlign = TextAlign.Center,
                )
                if (!ui.isConnected && !ui.isConnecting) {
                    Spacer(Modifier.height(10.dp))
                    Button(onClick = onReconnect) {
                        Text("Kết nối lại máy chủ")
                    }
                }
            }
        }
    }
}

@Composable
private fun EnrollmentScreen(
    ui: AttendUiState,
    hasCameraPermission: Boolean,
    onRequestCamera: () -> Unit,
    onFrame: (vn.tad.attend.camera.AnalyzedFrame) -> Unit,
    onStart: (String) -> Unit,
    onCancel: () -> Unit,
) {
    var ticketInput by rememberSaveable { mutableStateOf(ui.enrollmentTicket) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("Đăng ký khuôn mặt nhân viên", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
        Text(
            text = "Quản trị tạo mã 10 phút trong mục Nhân viên trên web, nhập vào đây để chụp 5 mẫu khuôn mặt SFace.",
            style = MaterialTheme.typography.bodySmall,
            color = Color(0xFF52657B),
        )

        OutlinedTextField(
            value = ticketInput,
            onValueChange = { ticketInput = it.uppercase(Locale.US) },
            label = { Text("Mã đăng ký (hết hạn sau 10 phút)") },
            singleLine = true,
            enabled = !ui.enrollmentActive,
            keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters),
            modifier = Modifier.fillMaxWidth(),
        )

        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Button(
                onClick = { onStart(ticketInput) },
                enabled = ui.isConnected && !ui.enrollmentActive && ticketInput.trim().length >= 10,
            ) {
                Text("Bắt đầu thu 5 mẫu")
            }
            OutlinedButton(onClick = onCancel) {
                Text("Hủy")
            }
        }

        Text(
            text = "Tiến độ: ${ui.enrollmentSamples}/${ui.enrollmentRequired} mẫu",
            style = MaterialTheme.typography.titleSmall,
            fontWeight = FontWeight.SemiBold,
        )
        LinearProgressIndicator(
            progress = { ui.enrollmentSamples.toFloat() / ui.enrollmentRequired.toFloat() },
            modifier = Modifier.fillMaxWidth(),
        )

        Box(
            modifier = Modifier
                .fillMaxWidth()
                .height(300.dp)
                .clip(RoundedCornerShape(16.dp))
                .background(Color(0xFF0F172A)),
            contentAlignment = Alignment.Center,
        ) {
            if (hasCameraPermission) {
                CameraPreviewView(modifier = Modifier.fillMaxSize(), onFrame = onFrame)
            } else {
                Button(onClick = onRequestCamera) { Text("Cấp quyền Camera") }
            }
        }

        Surface(
            shape = RoundedCornerShape(12.dp),
            color = if (ui.isError) Color(0xFFFFF0EE) else Color(0xFFE8F1FE),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(
                text = ui.message,
                modifier = Modifier.padding(12.dp),
                style = MaterialTheme.typography.bodyMedium,
                color = if (ui.isError) Color(0xFF9B2222) else Color(0xFF142946),
            )
        }
    }
}

@Composable
private fun SettingsScreen(
    ui: AttendUiState,
    onSave: (String, String) -> Unit,
    onRefreshRoster: () -> Unit,
    onLock: () -> Unit,
) {
    var serverUrl by rememberSaveable(ui.serverUrl) { mutableStateOf(ui.serverUrl) }
    var newToken by rememberSaveable { mutableStateOf("") }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text("Cấu hình thiết bị", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            OutlinedButton(onClick = onLock) {
                Icon(Icons.Default.Lock, contentDescription = null, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text("Khóa")
            }
        }

        Card(
            colors = CardDefaults.cardColors(containerColor = Color.White),
            border = BorderStroke(1.dp, Color(0xFFE1E8F1)),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text("Kết nối máy chủ HTTPS", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                OutlinedTextField(
                    value = serverUrl,
                    onValueChange = { serverUrl = it },
                    label = { Text("Địa chỉ máy chủ (HTTPS)") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(
                    value = newToken,
                    onValueChange = { newToken = it },
                    label = {
                        Text(
                            if (ui.hasToken) "Token thiết bị (để trống nếu giữ nguyên)" else "Token thiết bị (tạo từ /admin/devices)",
                        )
                    },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Button(
                        onClick = {
                            onSave(serverUrl, newToken)
                            newToken = ""
                        },
                    ) {
                        Text("Lưu & Kết nối")
                    }
                    if (ui.isConnected) {
                        OutlinedButton(onClick = onRefreshRoster) {
                            Text("Đồng bộ NV")
                        }
                    }
                }
            }
        }

        Card(
            colors = CardDefaults.cardColors(containerColor = Color.White),
            border = BorderStroke(1.dp, Color(0xFFE1E8F1)),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.Badge, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                    Spacer(Modifier.width(6.dp))
                    Text("Thông tin thiết bị & AI", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold)
                }
                InfoRow("Trạng thái AI", ui.modelStatus)
                InfoRow("Phiên bản app", "V${BuildConfig.VERSION_NAME} (code ${BuildConfig.VERSION_CODE})")
                val info = ui.deviceInfo
                if (info != null) {
                    InfoRow("Tên thiết bị", info.name)
                    InfoRow("Chế độ", AttendanceViewModel.directionLabel(info.direction))
                    InfoRow("Model máy chủ", "${info.modelId} / ${info.modelVersion} (${info.embeddingDimensions}d)")
                    InfoRow("Ngưỡng máy chủ", String.format(Locale.US, "%.3f", info.minScore))
                    InfoRow("Nhân viên trong RAM", "${ui.rosterCount}")
                } else {
                    InfoRow("Kết nối", "Chưa kết nối")
                }
            }
        }
    }
}

@Composable
private fun InfoRow(label: String, value: String) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(label, style = MaterialTheme.typography.bodySmall, color = Color(0xFF52657B))
        Spacer(Modifier.width(12.dp))
        Text(
            value,
            style = MaterialTheme.typography.bodySmall,
            fontWeight = FontWeight.Medium,
            textAlign = TextAlign.End,
        )
    }
}

@Composable
private fun AdminPinGateScreen(
    ui: AttendUiState,
    onSubmit: (String, String) -> Unit,
) {
    var pin by remember { mutableStateOf("") }
    var confirmPin by remember { mutableStateOf("") }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Card(
            modifier = Modifier.fillMaxWidth(),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            border = BorderStroke(1.dp, Color(0xFFE1E8F1)),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(20.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(
                    text = if (ui.adminPinSet) "Mở khóa quản trị" else "Đặt mã PIN quản trị lần đầu",
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    text = if (ui.adminPinSet) {
                        "Nhập mã PIN quản trị để truy cập Đăng ký khuôn mặt và Cấu hình."
                    } else {
                        "Đặt mã PIN tối thiểu 6 ký tự để bảo vệ màn hình cấu hình và đăng ký trên máy chấm công."
                    },
                    style = MaterialTheme.typography.bodySmall,
                    color = Color(0xFF52657B),
                )
                OutlinedTextField(
                    value = pin,
                    onValueChange = { pin = it },
                    label = { Text("Mã PIN quản trị") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                    modifier = Modifier.fillMaxWidth(),
                )
                if (!ui.adminPinSet) {
                    OutlinedTextField(
                        value = confirmPin,
                        onValueChange = { confirmPin = it },
                        label = { Text("Nhập lại mã PIN") },
                        singleLine = true,
                        visualTransformation = PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                ui.pinError?.let { err ->
                    Text(err, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                }
                Button(
                    onClick = {
                        onSubmit(pin, confirmPin)
                        pin = ""
                        confirmPin = ""
                    },
                    enabled = pin.isNotEmpty(),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(if (ui.adminPinSet) "Mở khóa" else "Lưu mã PIN & Tiếp tục")
                }
            }
        }
    }
}
