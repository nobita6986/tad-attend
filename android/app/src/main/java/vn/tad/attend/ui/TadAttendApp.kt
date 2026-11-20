package vn.tad.attend.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Face
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
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
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import vn.tad.attend.BuildConfig
import vn.tad.attend.ml.LivenessStage
import vn.tad.attend.model.EmployeeOption
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
        if (selectedTab == 1 && ui.isConnected) {
            viewModel.refreshRoster()
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
                        onRequestRegistration = { customName ->
                            viewModel.requestAutoRegistration(customName)
                        },
                        onSyncRoster = viewModel::refreshRoster,
                    )

                    1 -> if (ui.adminUnlocked) {
                        EnrollmentScreen(
                            ui = ui,
                            hasCameraPermission = hasCameraPermission,
                            onRequestCamera = { permissionLauncher.launch(Manifest.permission.CAMERA) },
                            onFrame = viewModel::onCameraFrame,
                            onRefreshOptions = viewModel::refreshRoster,
                            onStartCreate = viewModel::startCreateAndEnroll,
                            onStartExisting = viewModel::startExistingEnrollment,
                            onCancel = viewModel::cancelEnrollment,
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
                            onRequestRegistration = { url, customName ->
                                viewModel.requestAutoRegistration(customName, url)
                                selectedTab = 0
                            },
                            onActivateByHardwareId = { url ->
                                viewModel.activateByHardwareIdNow(url)
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
    onRequestRegistration: (String) -> Unit,
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
            ui.livenessStage == LivenessStage.LOOK_STRAIGHT ||
                ui.livenessStage == LivenessStage.READY_TO_CAPTURE -> Color(0xFF10B981)
            ui.livenessStage == LivenessStage.VERIFY_LIVENESS -> Color(0xFF2563EB)
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
                Box(
                    modifier = Modifier
                        .size(width = 220.dp, height = 280.dp)
                        .border(2.dp, frameBorderColor.copy(alpha = 0.85f), CircleShape),
                )
                if (ui.isConnected) {
                    val stepPillText = when (ui.livenessStage) {
                        LivenessStage.LOOK_STRAIGHT, LivenessStage.READY_TO_CAPTURE -> "✓ Đã xác thực · Nhìn thẳng..."
                        LivenessStage.COOLDOWN_WAIT_LEAVE -> "✓ Đã ghi nhận"
                        LivenessStage.VERIFY_LIVENESS -> "Bước 1/2: Chớp mắt hoặc quay nhẹ đầu"
                        LivenessStage.FACE_TOO_SMALL -> "Đưa mặt lại gần hơn"
                        LivenessStage.MULTIPLE_FACES -> "Chỉ đứng 1 người trong khung"
                        else -> "Đưa khuôn mặt vào khung"
                    }
                    val pillBg = when (ui.livenessStage) {
                        LivenessStage.LOOK_STRAIGHT, LivenessStage.READY_TO_CAPTURE, LivenessStage.COOLDOWN_WAIT_LEAVE ->
                            Color(0xFF047857).copy(alpha = 0.88f)
                        LivenessStage.MULTIPLE_FACES -> Color(0xFFDC2626).copy(alpha = 0.90f)
                        else -> Color(0xFF0F172A).copy(alpha = 0.72f)
                    }
                    Surface(
                        color = pillBg,
                        shape = RoundedCornerShape(999.dp),
                        modifier = Modifier
                            .align(Alignment.TopCenter)
                            .padding(top = 12.dp, start = 12.dp, end = 12.dp),
                    ) {
                        Text(
                            text = stepPillText,
                            color = Color.White,
                            style = MaterialTheme.typography.labelMedium,
                            fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.padding(horizontal = 14.dp, vertical = 6.dp),
                        )
                    }
                }
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
                    val clipboard = LocalClipboardManager.current
                    var copiedHw by remember { mutableStateOf(false) }
                    var customDeviceName by remember { mutableStateOf("") }
                    val isPending = ui.registrationStatus == "pending"
                    val isRejected = ui.registrationStatus == "rejected"
                    val isRevoked = ui.registrationStatus == "revoked"

                    Spacer(Modifier.height(10.dp))
                    Surface(
                        color = if (isPending) Color(0xFFFFFBEB) else Color(0xFFEFF6FF),
                        shape = RoundedCornerShape(10.dp),
                        border = BorderStroke(1.dp, if (isPending) Color(0xFFF59E0B) else Color(0xFFBFDBFE)),
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(horizontal = 12.dp, vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween,
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text("Mã máy cố định", style = MaterialTheme.typography.labelSmall, color = Color(0xFF475569))
                                Text(
                                    text = ui.hardwareId,
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontFamily = FontFamily.Monospace,
                                    fontWeight = FontWeight.Bold,
                                    color = Color(0xFF142946),
                                )
                            }
                            OutlinedButton(
                                onClick = {
                                    clipboard.setText(AnnotatedString(ui.hardwareId))
                                    copiedHw = true
                                },
                            ) {
                                Icon(Icons.Default.ContentCopy, contentDescription = null, modifier = Modifier.size(15.dp))
                                Spacer(Modifier.width(4.dp))
                                Text(if (copiedHw) "Đã chép" else "Sao chép")
                            }
                        }
                    }

                    if (isPending) {
                        Spacer(Modifier.height(10.dp))
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
                            Text(
                                text = "Tự động kiểm tra duyệt mỗi 5 giây...",
                                style = MaterialTheme.typography.bodySmall,
                                color = Color(0xFF92400E),
                                fontWeight = FontWeight.Medium,
                            )
                        }
                        Spacer(Modifier.height(8.dp))
                        OutlinedButton(onClick = onReconnect) {
                            Text("Kiểm tra ngay")
                        }
                    } else if (!isRevoked) {
                        Spacer(Modifier.height(8.dp))
                        OutlinedTextField(
                            value = customDeviceName,
                            onValueChange = { customDeviceName = it },
                            label = { Text("Tên thiết bị (tùy chọn, vd: Cổng chính)") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth(),
                        )
                        Spacer(Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            Button(
                                onClick = { onRequestRegistration(customDeviceName) },
                                modifier = Modifier.weight(1f),
                            ) {
                                Text(if (isRejected) "Gửi đăng ký lại" else "Đăng ký thiết bị")
                            }
                            OutlinedButton(onClick = onReconnect) {
                                Text("Kết nối lại")
                            }
                        }
                    } else {
                        Spacer(Modifier.height(10.dp))
                        OutlinedButton(onClick = onReconnect) {
                            Text("Thử kết nối lại")
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EnrollmentScreen(
    ui: AttendUiState,
    hasCameraPermission: Boolean,
    onRequestCamera: () -> Unit,
    onFrame: (vn.tad.attend.camera.AnalyzedFrame) -> Unit,
    onRefreshOptions: () -> Unit,
    onStartCreate: (String, String, String?, String?, Int?, Boolean) -> Unit,
    onStartExisting: (EmployeeOption?, Boolean) -> Unit,
    onCancel: () -> Unit,
) {
    var createMode by rememberSaveable { mutableStateOf(true) }
    var empCode by rememberSaveable { mutableStateOf("") }
    var fullName by rememberSaveable { mutableStateOf("") }
    var gender by rememberSaveable { mutableStateOf("male") }
    var department by rememberSaveable { mutableStateOf("") }
    var selectedShiftId by rememberSaveable { mutableStateOf<Int?>(null) }
    var shiftMenuOpen by remember { mutableStateOf(false) }

    var selectedEmployeeId by rememberSaveable { mutableStateOf<Int?>(null) }
    var employeeMenuOpen by remember { mutableStateOf(false) }
    var consentChecked by rememberSaveable { mutableStateOf(false) }

    // Default to first shift if there is only one configured on server
    LaunchedEffect(ui.availableShifts) {
        if (selectedShiftId == null && ui.availableShifts.size == 1) {
            selectedShiftId = ui.availableShifts.first().id
        }
    }

    // Clear form fields cleanly right after a successful creation/enrollment
    LaunchedEffect(ui.enrollmentSuccessNonce) {
        if (ui.enrollmentSuccessNonce > 0) {
            empCode = ""
            fullName = ""
            department = ""
            consentChecked = false
            selectedEmployeeId = null
        }
    }

    val selectedShift = ui.availableShifts.find { it.id == selectedShiftId }
    val selectedEmployee = ui.availableEmployees.find { it.id == selectedEmployeeId }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text("Đăng ký nhân viên & khuôn mặt", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            IconButton(onClick = onRefreshOptions, enabled = !ui.enrollmentActive) {
                Icon(Icons.Default.Refresh, contentDescription = "Tải lại danh sách")
            }
        }

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(
                selected = createMode,
                onClick = { if (!ui.enrollmentActive) createMode = true },
                label = { Text("Tạo nhân viên mới") },
            )
            FilterChip(
                selected = !createMode,
                onClick = { if (!ui.enrollmentActive) createMode = false },
                label = { Text("Cập nhật NV đã có (${ui.availableEmployees.size})") },
            )
        }

        if (createMode) {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, Color(0xFFE1E8F1)),
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(14.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    OutlinedTextField(
                        value = empCode,
                        onValueChange = { empCode = it.uppercase(Locale.US) },
                        label = { Text("Mã nhân viên * (vd: NV001)") },
                        singleLine = true,
                        enabled = !ui.enrollmentActive,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = fullName,
                        onValueChange = { fullName = it },
                        label = { Text("Họ và tên *") },
                        singleLine = true,
                        enabled = !ui.enrollmentActive,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Row(
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Text("Giới tính:", style = MaterialTheme.typography.bodySmall, color = Color(0xFF52657B))
                        listOf("male" to "Nam", "female" to "Nữ", "other" to "Khác").forEach { (key, label) ->
                            FilterChip(
                                selected = gender == key,
                                onClick = { if (!ui.enrollmentActive) gender = key },
                                label = { Text(label) },
                            )
                        }
                    }
                    OutlinedTextField(
                        value = department,
                        onValueChange = { department = it },
                        label = { Text("Bộ phận (không bắt buộc)") },
                        singleLine = true,
                        enabled = !ui.enrollmentActive,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    if (ui.availableShifts.isNotEmpty()) {
                        ExposedDropdownMenuBox(
                            expanded = shiftMenuOpen && !ui.enrollmentActive,
                            onExpandedChange = { if (!ui.enrollmentActive) shiftMenuOpen = it },
                        ) {
                            OutlinedTextField(
                                value = selectedShift?.let {
                                    "${it.name} (${it.startsAt.take(5)}–${it.endsAt.take(5)})"
                                } ?: "Chưa chọn ca làm",
                                onValueChange = {},
                                readOnly = true,
                                label = { Text("Ca làm mặc định") },
                                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = shiftMenuOpen) },
                                modifier = Modifier
                                    .menuAnchor(MenuAnchorType.PrimaryNotEditable)
                                    .fillMaxWidth(),
                            )
                            ExposedDropdownMenu(
                                expanded = shiftMenuOpen && !ui.enrollmentActive,
                                onDismissRequest = { shiftMenuOpen = false },
                            ) {
                                DropdownMenuItem(
                                    text = { Text("Chưa phân ca") },
                                    onClick = {
                                        selectedShiftId = null
                                        shiftMenuOpen = false
                                    },
                                )
                                ui.availableShifts.forEach { sh ->
                                    DropdownMenuItem(
                                        text = { Text("${sh.name} (${sh.startsAt.take(5)}–${sh.endsAt.take(5)})") },
                                        onClick = {
                                            selectedShiftId = sh.id
                                            shiftMenuOpen = false
                                        },
                                    )
                                }
                            }
                        }
                    }
                }
            }
        } else {
            Card(
                colors = CardDefaults.cardColors(containerColor = Color.White),
                border = BorderStroke(1.dp, Color(0xFFE1E8F1)),
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(14.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    if (ui.availableEmployees.isEmpty()) {
                        Text(
                            "Chưa có nhân viên nào trên máy chủ. Hãy chuyển sang \"Tạo nhân viên mới\".",
                            style = MaterialTheme.typography.bodySmall,
                            color = Color(0xFF52657B),
                        )
                    } else {
                        ExposedDropdownMenuBox(
                            expanded = employeeMenuOpen && !ui.enrollmentActive,
                            onExpandedChange = { if (!ui.enrollmentActive) employeeMenuOpen = it },
                        ) {
                            OutlinedTextField(
                                value = selectedEmployee?.let {
                                    "${it.code} · ${it.fullName} (${if (it.hasFace) "Đã có mặt" else "Chưa có mặt"})"
                                } ?: "Chọn nhân viên...",
                                onValueChange = {},
                                readOnly = true,
                                label = { Text("Nhân viên cần đăng ký / cập nhật mặt") },
                                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = employeeMenuOpen) },
                                modifier = Modifier
                                    .menuAnchor(MenuAnchorType.PrimaryNotEditable)
                                    .fillMaxWidth(),
                            )
                            ExposedDropdownMenu(
                                expanded = employeeMenuOpen && !ui.enrollmentActive,
                                onDismissRequest = { employeeMenuOpen = false },
                            ) {
                                ui.availableEmployees.forEach { emp ->
                                    DropdownMenuItem(
                                        text = {
                                            Text(
                                                "${emp.code} · ${emp.fullName} — ${if (emp.hasFace) "Đã có mặt" else "Chưa có mặt"}",
                                                color = if (emp.hasFace) Color(0xFF52657B) else Color(0xFF1955AA),
                                            )
                                        },
                                        onClick = {
                                            selectedEmployeeId = emp.id
                                            employeeMenuOpen = false
                                        },
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }

        Row(
            modifier = Modifier
                .fillMaxWidth()
                .clickable(enabled = !ui.enrollmentActive) { consentChecked = !consentChecked },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Checkbox(
                checked = consentChecked,
                onCheckedChange = { if (!ui.enrollmentActive) consentChecked = it },
            )
            Text(
                text = "Nhân viên xác nhận đồng ý cung cấp khuôn mặt để chấm công.",
                style = MaterialTheme.typography.bodySmall,
                color = Color(0xFF334155),
            )
        }

        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Button(
                onClick = {
                    if (createMode) {
                        onStartCreate(empCode, fullName, gender, department, selectedShiftId, consentChecked)
                    } else {
                        onStartExisting(selectedEmployee, consentChecked)
                    }
                },
                enabled = ui.isConnected && !ui.enrollmentActive && consentChecked && (
                    if (createMode) empCode.isNotBlank() && fullName.isNotBlank() else selectedEmployee != null
                ),
            ) {
                Text(if (createMode) "Chụp 5 mẫu & Lưu NV" else "Chụp 5 mẫu & Cập nhật")
            }
            if (ui.enrollmentActive) {
                OutlinedButton(onClick = onCancel) {
                    Text("Hủy chụp")
                }
            }
        }

        if (ui.enrollmentActive || ui.enrollmentSamples > 0) {
            Text(
                text = "Tiến độ: ${ui.enrollmentSamples}/${ui.enrollmentRequired} mẫu",
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
            )
            LinearProgressIndicator(
                progress = { ui.enrollmentSamples.toFloat() / ui.enrollmentRequired.toFloat() },
                modifier = Modifier.fillMaxWidth(),
            )
        }

        Surface(
            shape = RoundedCornerShape(12.dp),
            color = if (ui.enrollmentError) Color(0xFFFFF0EE) else Color(0xFFE8F1FE),
            modifier = Modifier.fillMaxWidth(),
        ) {
            Text(
                text = ui.enrollmentStatus,
                modifier = Modifier.padding(12.dp),
                style = MaterialTheme.typography.bodyMedium,
                color = if (ui.enrollmentError) Color(0xFF9B2222) else Color(0xFF142946),
            )
        }

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
                Box(
                    modifier = Modifier
                        .size(width = 180.dp, height = 220.dp)
                        .border(
                            2.dp,
                            if (ui.enrollmentActive) Color(0xFF22C55E) else Color.White.copy(alpha = 0.55f),
                            CircleShape,
                        ),
                )
            } else {
                Button(onClick = onRequestCamera) { Text("Cấp quyền Camera") }
            }
        }
    }
}

@Composable
private fun SettingsScreen(
    ui: AttendUiState,
    onSave: (String, String) -> Unit,
    onRequestRegistration: (String, String) -> Unit,
    onActivateByHardwareId: (String) -> Unit,
    onRefreshRoster: () -> Unit,
    onLock: () -> Unit,
) {
    var serverUrl by rememberSaveable(ui.serverUrl) { mutableStateOf(ui.serverUrl) }
    var newToken by rememberSaveable { mutableStateOf("") }
    var customDeviceName by rememberSaveable { mutableStateOf("") }
    val clipboard = LocalClipboardManager.current
    var copiedHw by remember { mutableStateOf(false) }

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
            colors = CardDefaults.cardColors(containerColor = Color(0xFFEFF6FF)),
            border = BorderStroke(1.dp, Color(0xFFBFDBFE)),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text("Mã máy cố định (Hardware ID)", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.Bold, color = Color(0xFF142946))
                Text(
                    text = "Bấm 'Đăng ký thiết bị tự động' để gửi Mã máy này lên trang Quản trị → Thiết bị. Sau khi Admin bấm Đồng ý, máy sẽ tự kết nối mãi mãi kể cả khi nâng cấp hay cài lại app.",
                    style = MaterialTheme.typography.bodySmall,
                    color = Color(0xFF475569),
                )
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Text(
                        text = ui.hardwareId,
                        style = MaterialTheme.typography.titleSmall,
                        fontFamily = FontFamily.Monospace,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF1955AA),
                    )
                    OutlinedButton(
                        onClick = {
                            clipboard.setText(AnnotatedString(ui.hardwareId))
                            copiedHw = true
                        },
                    ) {
                        Icon(Icons.Default.ContentCopy, contentDescription = null, modifier = Modifier.size(15.dp))
                        Spacer(Modifier.width(4.dp))
                        Text(if (copiedHw) "Đã chép" else "Sao chép")
                    }
                }
                OutlinedTextField(
                    value = customDeviceName,
                    onValueChange = { customDeviceName = it },
                    label = { Text("Tên thiết bị khi gửi đăng ký (tùy chọn)") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Button(
                        onClick = { onRequestRegistration(serverUrl, customDeviceName) },
                        modifier = Modifier.weight(1f),
                    ) {
                        Text("Đăng ký tự động")
                    }
                    OutlinedButton(onClick = { onActivateByHardwareId(serverUrl) }) {
                        Text("Kích hoạt ngay")
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
                            if (ui.hasToken) "Token thủ công (không cần nếu dùng Mã máy)" else "Token thủ công (tùy chọn dự phòng)",
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
                        "Nhập mã PIN quản trị để truy cập Đăng ký nhân viên và Cấu hình."
                    } else {
                        "Đặt mã PIN tối thiểu 6 ký tự để bảo vệ màn hình đăng ký và cấu hình trên máy chấm công."
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
