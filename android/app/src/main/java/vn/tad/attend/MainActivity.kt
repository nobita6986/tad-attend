package vn.tad.attend

import android.os.Bundle
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.viewModels
import vn.tad.attend.ui.AttendanceViewModel
import vn.tad.attend.ui.TadAttendApp

class MainActivity : ComponentActivity() {
    private val viewModel: AttendanceViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // Kiosk device: keep screen awake while attendance screen is open.
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        setContent {
            TadAttendApp(viewModel = viewModel)
        }
    }

    override fun onStop() {
        super.onStop()
        viewModel.lockAdmin()
    }
}
