// 表示する年月
let currentDate = new Date();


// 出退勤データ
// 実際にはAPIから取得する想定
const attendanceData = {
    "2026-09-01": {
        start: "08:52",
        end: "18:11"
    },

    "2026-09-02": {
        start: "08:59",
        end: "18:22"
    },

    "2026-09-03": {
        start: "08:51",
        end: "18:33"
    },

    "2026-09-04": {
        start: "08:59",
        end: "18:44"
    },

    "2026-09-07": {
        start: "08:49",
        end: "18:27"
    },

    "2026-09-08": {
        start: "08:56",
        end: "18:38"
    },

    "2026-09-15": {
        start: "08:45",
        end: "18:15",
        status: "申請中"
    },

    "2026-09-17": {
        start: "08:59",
        end: "18:37",
        status: "承認済み"
    }
};


// カレンダー生成
function createCalendar() {

    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const calendar = document.getElementById("calendar");
    const monthTitle = document.getElementById("monthTitle");

    // 月タイトル
    monthTitle.textContent =
        `${year}年${month + 1}月`;

    // 一旦クリア
    calendar.innerHTML = "";


    // 月の1日が何曜日か
    const firstDay =
        new Date(year, month, 1).getDay();


    // 月の日数
    const lastDate =
        new Date(year, month + 1, 0).getDate();


    // 前月部分の空白
    for (let i = 0; i < firstDay; i++) {

        const empty = document.createElement("div");

        empty.className = "day empty";

        calendar.appendChild(empty);
    }


    // 日付生成
    for (let day = 1; day <= lastDate; day++) {

        const cell = document.createElement("div");

        cell.className = "day";


        // 日付
        const dateElement =
            document.createElement("div");

        dateElement.className = "date";

        dateElement.textContent = day;

        cell.appendChild(dateElement);


        // 曜日
        const date =
            new Date(year, month, day);

        const dayOfWeek =
            date.getDay();


        // 土日
        if (dayOfWeek === 0 ||
            dayOfWeek === 6) {

            cell.classList.add("holiday");
        }


        // 今日
        const today = new Date();

        if (
            year === today.getFullYear() &&
            month === today.getMonth() &&
            day === today.getDate()
        ) {

            cell.classList.add("today");
        }


        // YYYY-MM-DD
        const key =
            `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;


        // 出退勤データ
        const record =
            attendanceData[key];


        if (record) {

            const recordElement =
                document.createElement("div");

            recordElement.className = "record";

            recordElement.innerHTML =
                `出 ${record.start}<br>
         退 ${record.end ?? "－"}`;

            cell.appendChild(recordElement);


            // ステータス
            if (record.status) {

                const status =
                    document.createElement("div");

                status.textContent =
                    record.status;

                status.className =
                    "status";

                cell.appendChild(status);
            }
        }


        calendar.appendChild(cell);
    }
}


// 前月
document
    .getElementById("prevMonth")
    .addEventListener("click", () => {

        currentDate.setMonth(
            currentDate.getMonth() - 1
        );

        createCalendar();
    });


// 次月
document
    .getElementById("nextMonth")
    .addEventListener("click", () => {

        currentDate.setMonth(
            currentDate.getMonth() + 1
        );

        createCalendar();
    });


// 初回表示
createCalendar();
